<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\FolderMailbox;
use AvalynxOfficeEas\Db\Model\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Doctrine queries for the EAS mail backend.
 */
final readonly class MailFolderService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return Folder[]
     */
    public function foldersForMailbox(Mailbox $mailbox): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Folder::class, 'f')
            ->join(FolderMailbox::class, 'fl', 'WITH', 'fl.folder = f')
            ->where('fl.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->orderBy('fl.sortOrder', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->getQuery();

        /** @var list<Folder> $result */
        $result = $query->getResult();

        return $result;
    }

    public function folderForMailbox(Mailbox $mailbox, UuidV7 $folderId): ?Folder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Folder::class, 'f')
            ->join(FolderMailbox::class, 'fl', 'WITH', 'fl.folder = f')
            ->where('fl.mailbox = :mailbox')
            ->andWhere('f.id = :folderId')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('folderId', $folderId)
            ->setMaxResults(1)
            ->getQuery();

        $result = $query->getOneOrNullResult();

        \assert($result instanceof Folder || null === $result);

        return $result;
    }

    public function emailForFolder(Mailbox $mailbox, Folder $folder, UuidV7 $emailId): ?Email
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e', 'ef', 'eal', 'ea', 'ebl', 'b', 'eat', 'at')
            ->from(Email::class, 'e')
            ->join('e.emailFolders', 'ef')
            ->leftJoin('e.emailAddresses', 'eal')
            ->leftJoin('eal.address', 'ea')
            ->leftJoin('e.emailBodies', 'ebl')
            ->leftJoin('ebl.body', 'b')
            ->leftJoin('e.emailAttachments', 'eat')
            ->leftJoin('eat.attachment', 'at')
            ->where('ef.folder = :folder')
            ->andWhere('e.mailbox = :mailbox')
            ->andWhere('e.id = :emailId')
            ->setParameter('folder', $folder)
            ->setParameter('mailbox', $mailbox)
            ->setParameter('emailId', $emailId)
            ->setMaxResults(1)
            ->getQuery();

        $result = $query->getOneOrNullResult();

        \assert($result instanceof Email || null === $result);

        return $result;
    }

    /**
     * @param int<0, max> $cutoffdate timestamp, 0 disables the filter
     *
     * @return Email[]
     */
    public function emailsForFolder(Mailbox $mailbox, Folder $folder, int $cutoffdate): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Email::class, 'e')
            ->join('e.emailFolders', 'ef')
            ->where('ef.folder = :folder')
            ->andWhere('e.mailbox = :mailbox')
            ->setParameter('folder', $folder)
            ->setParameter('mailbox', $mailbox)
            ->addSelect('COALESCE(e.receivedAt, e.sentAt) AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if ($cutoffdate > 0) {
            $queryBuilder
                ->andWhere('e.receivedAt >= :cutoff OR e.sentAt >= :cutoff')
                ->setParameter('cutoff', new \DateTimeImmutable('@'.$cutoffdate));
        }

        /** @var list<Email> $result */
        $result = $queryBuilder->getQuery()->getResult();

        return $result;
    }
}
