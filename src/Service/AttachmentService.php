<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\EmailAttachmentLink;
use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Mail\AttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Doctrine queries for attachments in the EAS backend.
 */
final readonly class AttachmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AttachmentStorage $attachmentStorage,
    ) {
    }

    public function linkFor(Mailbox $mailbox, UuidV7 $emailId, UuidV7 $attachmentId): ?EmailAttachmentLink
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('eal')
            ->from(EmailAttachmentLink::class, 'eal')
            ->join('eal.email', 'e')
            ->join('e.mailbox', 'm')
            ->where('e.id = :emailId')
            ->andWhere('eal.attachment = :attachmentId')
            ->andWhere('m.id = :mailboxId')
            ->setParameter('emailId', $emailId)
            ->setParameter('attachmentId', $attachmentId)
            ->setParameter('mailboxId', $mailbox->getId())
            ->setMaxResults(1)
            ->getQuery();

        $result = $query->getOneOrNullResult();

        \assert($result instanceof EmailAttachmentLink || null === $result);

        return $result;
    }

    public function contentOf(EmailAttachmentLink $link): ?string
    {
        $attachment = $link->getAttachment();

        if (null === $attachment) {
            return null;
        }

        try {
            $path = $this->attachmentStorage->resolveAbsolutePath((string) $attachment->getFilePath());
        } catch (\RuntimeException) {
            return null;
        }

        $content = @file_get_contents($path);

        return false === $content ? null : $content;
    }

    public function storage(): AttachmentStorage
    {
        return $this->attachmentStorage;
    }
}
