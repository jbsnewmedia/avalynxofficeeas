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

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FolderService $folderService,
        private AttachmentStorage $attachmentStorage,
        private LicenseState $licenseState,
    ) {
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
            throw new \RuntimeException(sprintf('Unable to parse message: %s', $throwable->getMessage()), 0, $throwable);
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
        $message = new \Laminas\Mail\Storage\Message(['raw' => $mime]);
        $email = new Email();
        $email->setAccount($account);
        $email->setIsOutgoing(true)->setIsRead(true);
        $email->setImportKey('eas|'.$clientKey);
        $email->setContentHash(hash('sha256', $mime));
        $email->setSubject((string) $this->header($message, 'subject'));
        $email->setReceivedAt(new \DateTimeImmutable());
        $email->setMessageId((string) $this->header($message, 'message-id'));

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

    private function linkHeaderAddresses(Email $email, \Laminas\Mail\Storage\Message $message, string $headerName, EmailAddressType $type, ?string $requiredAddress = null): bool
    {
        $value = $this->header($message, $headerName);

        if (null === $value) {
            return false;
        }

        $header = $message->getHeader($headerName);
        $linked = false;

        if ($header instanceof \Laminas\Mail\Header\AbstractAddressList) {
            foreach ($header->getAddressList() as $address) {
                $emailAddress = trim((string) $address->getEmail(), "<> \t\n\r");
                if (null !== $requiredAddress && strtolower($emailAddress) !== $requiredAddress) {
                    continue;
                }

                $this->linkAddress($email, $emailAddress, $address->getName(), $type);
                $linked = true;
            }
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

    private function extractParts(\Laminas\Mail\Storage\Message $message, Email $email): void
    {
        $textBody = '';
        $htmlBody = '';

        if ($message->isMultipart()) {
            /** @var \Laminas\Mail\Storage\Part $part */
            foreach (new \RecursiveIteratorIterator($message, \RecursiveIteratorIterator::SELF_FIRST) as $part) {
                $contentType = (string) $this->header($part, 'content-type');
                $disposition = (string) $this->header($part, 'content-disposition');

                if (str_contains($disposition, 'attachment') || $this->hasFileName($part)) {
                    $this->storeAttachmentPart($part, $email, $this->partMimeType($contentType));

                    continue;
                }

                $data = $this->getPartContent($part);

                if (str_starts_with(strtolower($contentType), 'text/html')) {
                    $htmlBody .= $data;
                } elseif (str_starts_with(strtolower($contentType), 'text/plain')) {
                    $textBody .= $data;
                }
            }
        } else {
            $textBody = $this->getPartContent($message);
        }

        $this->addBody($email, $htmlBody, BodyContentType::HTML);
        $this->addBody($email, $textBody, BodyContentType::TEXT);

        if (null === $email->getPreviewBody()) {
            $email->setPreviewBody(mb_substr($textBody, 0, 500));
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

    private function getPartContent(\Laminas\Mail\Storage\Part $part): string
    {
        $data = (string) $part->getContent();

        $encoding = strtolower((string) $this->header($part, 'content-transfer-encoding'));

        return match ($encoding) {
            'quoted-printable' => quoted_printable_decode($data),
            'base64' => base64_decode($data, true) ?: '',
            default => $data,
        };
    }

    private function hasFileName(\Laminas\Mail\Storage\Part $part): bool
    {
        return null !== $this->fileName($part);
    }

    private function fileName(\Laminas\Mail\Storage\Part $part): ?string
    {
        $headers = $part->getHeaders();
        $disposition = $headers->has('content-disposition') ? $headers->get('content-disposition') : null;

        if ($disposition instanceof \Laminas\Mail\Header\HeaderInterface) {
            $name = $this->parameterFromValue($disposition->getFieldValue(), 'filename');

            if (null !== $name) {
                return $name;
            }
        }

        $contentType = $headers->has('content-type') ? $headers->get('content-type') : null;

        if ($contentType instanceof \Laminas\Mail\Header\ContentType) {
            $name = $contentType->getParameter('name');

            if (is_string($name) && '' !== $name) {
                return $name;
            }
        }

        return null;
    }

    private function parameterFromValue(string $value, string $parameter): ?string
    {
        if (1 !== preg_match(sprintf('/%s\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', preg_quote($parameter, '/')), $value, $matches)) {
            return null;
        }

        $name = '' !== $matches[1] ? $matches[1] : $matches[2];

        return '' !== $name ? $name : null;
    }

    private function header(\Laminas\Mail\Storage\Part $part, string $name): ?string
    {
        try {
            $headers = $part->getHeaders();

            if (!$headers->has($name)) {
                return null;
            }

            $header = $headers->get($name);

            if (!$header instanceof \Laminas\Mail\Header\HeaderInterface) {
                return null;
            }

            return $header->getFieldValue();
        } catch (\Throwable) {
            return null;
        }
    }

    private function partMimeType(string $contentType): string
    {
        $type = trim(strtolower($contentType));

        return '' !== $type ? strtok($type, ';') ?: $type : 'application/octet-stream';
    }

    private function storeAttachmentPart(\Laminas\Mail\Storage\Part $part, Email $email, string $contentType): void
    {
        $data = $this->getPartContent($part);

        if ('' === $data) {
            return;
        }

        $attachment = new Attachment();
        $attachment->setName($this->fileName($part) ?? 'attachment.bin');
        $attachment->setSize(strlen($data));
        $attachment->setMimeType($contentType);
        $attachment->setSha1(sha1($data));

        try {
            $attachment->setFilePath($this->attachmentStorage->store($attachment, $data));
        } catch (\RuntimeException) {
            return;
        }

        $this->entityManager->persist($attachment);

        $link = new EmailAttachmentLink();
        $link->setEmail($email);
        $link->setAttachment($attachment);
        $email->addEmailAttachment($link);
        $this->entityManager->persist($link);
    }
}
