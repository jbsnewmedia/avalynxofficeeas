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
 * before the actual submission. In read-only mode the message is stored
 * with a license hold instead of being queued.
 */
final readonly class SendMailService
{
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

        // The message is stored locally first (data safety). The licensing
        // policy decides whether it is handed to the core queue (normal) or
        // paused with an explicit license hold (read-only mode).
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

        if ($this->licenseState->isReadOnly()) {
            $email->setLicenseHold(true);
            $this->entityManager->flush();

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
        $email->setSubject((string) $message->getHeader('subject'));
        $email->setReceivedAt(new \DateTimeImmutable());
        $email->setMessageId((string) $message->getHeader('message-id'));

        if (!$this->linkHeaderAddresses($email, $message, 'from', EmailAddressType::SENDER, strtolower(trim($account->getEmail())))) {
            $this->linkAddress($email, $account->getEmail(), null, EmailAddressType::SENDER);
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
            $emailAddress = trim($address['email'], "<> \t\n\r");
            if (null !== $requiredAddress && strtolower($emailAddress) !== $requiredAddress) {
                continue;
            }

            $this->linkAddress($email, $emailAddress, $address['name'], $type);
            $linked = true;
        }

        return $linked;
    }

    private function linkAddress(Email $email, string $emailAddr, ?string $name, EmailAddressType $type): void
    {
        $emailAddr = trim($emailAddr, "<> \t\n\r");

        if ('' !== $emailAddr) {
            $address = $this->addressRepository->findOneBy(['email' => $emailAddr]);

            if (null === $address) {
                $address = new Address();
                $address->setEmail($emailAddr);
                $this->entityManager->persist($address);
            }

            if (null !== $name && '' !== $name && null === $address->getName()) {
                $address->setName($name);
            }

            $link = new EmailAddressLink();
            $link->setEmail($email);
            $link->setAddress($address);
            $link->setType($type);
            $email->addEmailAddress($link);
            $this->entityManager->persist($link);
        }
    }

    private function extractParts(MimePart $message, Email $email): void
    {
        $textBody = '';
        $htmlBody = '';

        if ($message->isMultipart()) {
            $this->collectParts($message, $email, $textBody, $htmlBody);
        } elseif ($message->isAttachment()) {
            $this->storeAttachmentPart($message, $email);
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

    private function collectParts(MimePart $parent, Email $email, string &$textBody, string &$htmlBody): void
    {
        foreach ($parent->getParts() as $part) {
            if ($part->isMultipart()) {
                $this->collectParts($part, $email, $textBody, $htmlBody);

                continue;
            }

            if ($part->isAttachment()) {
                $this->storeAttachmentPart($part, $email);

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
     * @throws \RuntimeException when the attachment exceeds the configured limit or cannot be stored
     */
    private function storeAttachmentPart(MimePart $part, Email $email): void
    {
        $data = $part->getDecodedBody();

        if ('' === $data) {
            return;
        }

        $name = $part->getFileName() ?? 'attachment.bin';
        $maxBytes = $this->attachmentStorage->getMaxBytes();

        if (strlen($data) > $maxBytes) {
            $message = sprintf('Attachment "%s" (%d bytes) exceeds the limit of %d bytes', $name, strlen($data), $maxBytes);
            $this->log($message);

            throw new \RuntimeException($message);
        }

        $attachment = new Attachment();
        $attachment->setName($name);
        $attachment->setSize(strlen($data));
        $attachment->setMimeType($part->getContentType());
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
