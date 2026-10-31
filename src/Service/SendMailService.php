<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\Attachment;
use AvalynxOfficeEas\Db\Model\Body;
use AvalynxOfficeEas\Db\Model\BodyContentType;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailAddressLink;
use AvalynxOfficeEas\Db\Model\EmailAddressType;
use AvalynxOfficeEas\Db\Model\EmailAttachmentLink;
use AvalynxOfficeEas\Db\Model\EmailBodyLink;
use AvalynxOfficeEas\Licensing\LicenseState;
use AvalynxOfficeEas\Mail\AttachmentStorage;
use AvalynxOfficeEas\Mail\MimeParser;
use AvalynxOfficeEas\Mail\MimePart;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts RFC822 messages sent by EAS clients (SendMail/SmartForward/SmartReply)
 * into outgoing Email models and hands them to the core mail queue.
 *
 * The plugin never transmits via SMTP itself: the core worker
 * (app:mail:send) owns delivery and enforces the licensing policy again
 * before the actual submission. In read-only mode the submission is rejected
 * before anything is parsed or persisted - consistent with every other
 * device-initiated write, nothing of the message reaches the shared database
 * or the attachment volume.
 */
final readonly class SendMailService
{
    private const int SUBJECT_MAX_LENGTH = 255;
    private const int MESSAGE_ID_MAX_LENGTH = 255;
    private const int ADDRESS_MAX_LENGTH = 190;
    private const int ATTACHMENT_NAME_MAX_LENGTH = 255;
    private const int MIME_TYPE_MAX_LENGTH = 190;

    /** @var \Doctrine\ORM\EntityRepository<Address> */
    private \Doctrine\ORM\EntityRepository $addressRepository;

    private MimeParser $mimeParser;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FolderService $folderService,
        private AttachmentStorage $attachmentStorage,
        private LicenseState $licenseState,
    ) {
        $this->mimeParser = new MimeParser();
        $this->addressRepository = $this->entityManager->getRepository(Address::class);
    }

    public function send(\SyncSendMail $sendMail, Account $account): bool
    {
        // Read-only mode blocks every device-initiated write. The check runs
        // before the message is parsed or persisted: no bodies, attachments
        // or folder links may be written while the core reports READ_ONLY
        // (the device keeps the mail in its outbox and may retry).
        if ($this->licenseState->isReadOnly()) {
            $this->log('Submission rejected: the installation is in read-only mode');

            return false;
        }

        $mime = is_string($sendMail->mime) ? $sendMail->mime : '';

        if ('' === $mime) {
            return false;
        }

        try {
            $clientKey = is_string($sendMail->clientid) ? $sendMail->clientid : 'eas';
            $email = $this->convertMimeToEmail($mime, $account, $clientKey);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(sprintf('Unable to process message: %s', $throwable->getMessage()), 0, $throwable);
        }

        // The email is persisted before the folder synchronization flushes:
        // new system folders mid-conversion must not see unpersisted links.
        $this->entityManager->persist($email);

        // The message is stored locally first (data safety); the licensing
        // policy was checked above, so it is handed to the core queue.
        try {
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // the client re-sent a message that is already stored
            return false;
        }

        try {
            $this->folderService->syncEmailSystemFolders($email);
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // the client re-sent a message that is already stored
            return false;
        }

        return true;
    }

    /**
     * @throws \Throwable when the message cannot be parsed
     */
    private function convertMimeToEmail(string $mime, Account $account, string $clientKey): Email
    {
        $message = $this->mimeParser->parse($mime);
        $email = new Email();
        $email->setAccount($account);
        $email->setIsOutgoing(true)->setIsRead(true);
        $email->setImportKey('eas|'.$clientKey);
        $email->setContentHash(hash('sha256', $mime));
        $email->setSubject(self::sanitize((string) $message->getHeader('subject'), self::SUBJECT_MAX_LENGTH));
        $email->setReceivedAt(new \DateTimeImmutable());
        $email->setMessageId(self::sanitize((string) $message->getHeader('message-id'), self::MESSAGE_ID_MAX_LENGTH));

        if (!$this->linkHeaderAddresses($email, $message, 'from', EmailAddressType::SENDER, strtolower(trim($account->getEmail())))) {
            $this->linkAddress($email, $account->getEmail(), EmailAddressType::SENDER);
        }
        $this->linkHeaderAddresses($email, $message, 'to', EmailAddressType::RECIPIENT);
        $this->linkHeaderAddresses($email, $message, 'cc', EmailAddressType::CC);
        $this->linkHeaderAddresses($email, $message, 'bcc', EmailAddressType::BCC);
        $this->linkHeaderAddresses($email, $message, 'reply-to', EmailAddressType::REPLY_TO);

        $this->extractParts($message, $email);

        return $email;
    }

    private function linkHeaderAddresses(Email $email, MimePart $message, string $headerName, EmailAddressType $type, ?string $requiredAddress = null): bool
    {
        $linked = false;

        foreach ($message->getAddresses($headerName) as $address) {
            $emailAddress = self::normalizeAddress($address['email']);
            if (null !== $requiredAddress && $emailAddress !== $requiredAddress) {
                continue;
            }

            $this->linkAddress($email, $emailAddress, $type);
            $linked = true;
        }

        return $linked;
    }

    /**
     * Links the address by e-mail only. The display name supplied by the
     * device is deliberately not persisted: `Address` rows are shared by all
     * accounts, so a client-chosen name would be shown to every other user
     * of the installation (display-name poisoning).
     *
     * Addresses are normalized to lower case and must pass FILTER_VALIDATE_EMAIL;
     * anything else aborts the submission (the device reports
     * MailSubmissionFailed) instead of persisting garbage in the shared table.
     *
     * @throws \RuntimeException when the address is not a valid e-mail address
     */
    private function linkAddress(Email $email, string $emailAddr, EmailAddressType $type): void
    {
        $emailAddr = self::normalizeAddress($emailAddr);

        if ('' === $emailAddr) {
            return;
        }

        if (strlen($emailAddr) > self::ADDRESS_MAX_LENGTH || false === filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
            $message = sprintf('Invalid %s address "%s"', $type->value, mb_substr($emailAddr, 0, 80));
            $this->log($message);

            throw new \RuntimeException($message);
        }

        $address = $this->addressRepository->findOneBy(['email' => $emailAddr]);

        if (null === $address) {
            $address = new Address();
            $address->setEmail($emailAddr);
            $this->entityManager->persist($address);
        }

        $link = new EmailAddressLink();
        $link->setEmail($email);
        $link->setAddress($address);
        $link->setType($type);
        $email->addEmailAddress($link);
        $this->entityManager->persist($link);
    }

    private static function normalizeAddress(string $emailAddr): string
    {
        return strtolower(trim(self::sanitize($emailAddr, PHP_INT_MAX), "<> \t\n\r"));
    }

    /**
     * Hardens a client-supplied string before it reaches a VARCHAR column:
     * strips NUL bytes (PostgreSQL rejects them), repairs invalid UTF-8 and
     * truncates to the column length (in characters).
     */
    private static function sanitize(string $value, int $maxLength): string
    {
        $value = str_replace("\0", '', $value);
        $value = MimeParser::convertToUtf8($value, null);

        return mb_substr($value, 0, $maxLength);
    }

    private function extractParts(MimePart $message, Email $email): void
    {
        $textBody = '';
        $htmlBody = '';
        $attachmentBytes = 0;

        if ($message->isMultipart()) {
            $this->collectParts($message, $email, $textBody, $htmlBody, $attachmentBytes);
        } elseif ($message->isAttachment()) {
            $this->storeAttachmentPart($message, $email, $attachmentBytes);
        } elseif ('text/html' === $message->getContentType()) {
            $htmlBody = $message->getTextBody();
        } else {
            $textBody = $message->getTextBody();
        }

        $this->addBody($email, $htmlBody, BodyContentType::HTML);
        $this->addBody($email, $textBody, BodyContentType::TEXT);

        if (null === $email->getPreviewBody()) {
            $email->setPreviewBody(mb_substr($textBody, 0, 500));
        }
    }

    private function collectParts(MimePart $parent, Email $email, string &$textBody, string &$htmlBody, int &$attachmentBytes): void
    {
        foreach ($parent->getParts() as $part) {
            if ($part->isMultipart()) {
                $this->collectParts($part, $email, $textBody, $htmlBody, $attachmentBytes);

                continue;
            }

            if ($part->isAttachment()) {
                $this->storeAttachmentPart($part, $email, $attachmentBytes);

                continue;
            }

            $contentType = $part->getContentType();

            if ('text/html' === $contentType) {
                $htmlBody .= $part->getTextBody();
            } elseif ('text/plain' === $contentType) {
                $textBody .= $part->getTextBody();
            }
        }
    }

    private function addBody(Email $email, string $content, BodyContentType $contentType): void
    {
        if ('' === $content) {
            return;
        }

        $body = new Body();
        $body->setContent($content);
        $this->entityManager->persist($body);

        $link = new EmailBodyLink();
        $link->setEmail($email);
        $link->setBody($body);
        $link->setContentType($contentType);
        $email->addEmailBody($link);
        $this->entityManager->persist($link);

        if (BodyContentType::HTML === $contentType && null === $email->getPreviewBody()) {
            $email->setPreviewBody(mb_substr(strip_tags($content), 0, 500));
        }
    }

    /**
     * The size limit (ATTACHMENT_STORAGE_MAX_BYTES) applies to every single
     * attachment and to the sum of all attachments of one message.
     *
     * @throws \RuntimeException when the attachment exceeds the configured limit or cannot be stored
     */
    private function storeAttachmentPart(MimePart $part, Email $email, int &$attachmentBytes): void
    {
        $data = $part->getDecodedBody();

        if ('' === $data) {
            return;
        }

        $name = self::sanitize($part->getFileName() ?? 'attachment.bin', self::ATTACHMENT_NAME_MAX_LENGTH);
        if ('' === $name) {
            $name = 'attachment.bin';
        }
        $maxBytes = $this->attachmentStorage->getMaxBytes();

        if (strlen($data) > $maxBytes) {
            $message = sprintf('Attachment "%s" (%d bytes) exceeds the limit of %d bytes', $name, strlen($data), $maxBytes);
            $this->log($message);

            throw new \RuntimeException($message);
        }

        $attachmentBytes += strlen($data);

        if ($attachmentBytes > $maxBytes) {
            $message = sprintf('Attachments (%d bytes in total) exceed the limit of %d bytes', $attachmentBytes, $maxBytes);
            $this->log($message);

            throw new \RuntimeException($message);
        }

        $mimeType = self::sanitize($part->getContentType(), self::MIME_TYPE_MAX_LENGTH);

        $attachment = new Attachment();
        $attachment->setName($name);
        $attachment->setSize(strlen($data));
        $attachment->setMimeType('' !== $mimeType ? $mimeType : 'application/octet-stream');
        $attachment->setSha1(sha1($data));

        try {
            $attachment->setFilePath($this->attachmentStorage->store($attachment, $data));
        } catch (\RuntimeException $exception) {
            $message = sprintf('Attachment "%s" could not be stored: %s', $name, $exception->getMessage());
            $this->log($message);

            throw new \RuntimeException($message, 0, $exception);
        }

        $this->entityManager->persist($attachment);

        $link = new EmailAttachmentLink();
        $link->setEmail($email);
        $link->setAttachment($attachment);
        $email->addEmailAttachment($link);
        $this->entityManager->persist($link);
    }

    private function log(string $message): void
    {
        if (class_exists(\ZLog::class) && defined('LOGLEVEL_WARN')) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('SendMailService: %s', $message));
        }
    }
}
