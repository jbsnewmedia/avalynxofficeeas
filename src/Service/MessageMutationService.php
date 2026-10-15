<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Licensing\LicenseState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Message mutations performed by EAS clients (read flags, favorites, moves
 * and deletions). Every mutation is blocked while the core reports the
 * read-only license mode.
 */
final readonly class MessageMutationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FolderService $folderService,
        private LicenseState $licenseState,
    ) {
    }

    public function setRead(Email $email, bool $read): void
    {
        $this->licenseState->assertMutationAllowed('MAIL_MODIFY');
        $email->setIsRead($read);
        $this->entityManager->flush();
    }

    public function setFavorite(Email $email, bool $favorite): void
    {
        $this->licenseState->assertMutationAllowed('MAIL_MODIFY');
        $email->setIsFavorite($favorite);
        $this->entityManager->flush();
    }

    public function move(Email $email, Folder $target, ?Folder $source = null): void
    {
        $this->licenseState->assertMutationAllowed('MAIL_MODIFY');
        $this->folderService->moveEmailToFolder($email, $target, $source);
        $this->entityManager->flush();
    }

    public function delete(Email $email): void
    {
        $this->licenseState->assertMutationAllowed('MAIL_DELETE');
        $this->removeLinks($email);
        $this->entityManager->remove($email);
        $this->entityManager->flush();
    }

    public function emptyFolder(Mailbox $mailbox, Folder $folder): int
    {
        $this->licenseState->assertMutationAllowed('MAIL_DELETE');
        $emails = $this->emailsForFolder($mailbox, $folder);

        foreach ($emails as $email) {
            $this->removeLinks($email);
            $this->entityManager->remove($email);
        }

        $this->entityManager->flush();

        return count($emails);
    }

    /**
     * The link collections are not cascade-removed with the email and their
     * in-memory state can be stale, so the link rows are loaded from the
     * repository before removal. Shared entities (addresses, bodies and
     * attachments) are kept.
     */
    private function removeLinks(Email $email): void
    {
        foreach ([\AvalynxOfficeEas\Db\Model\EmailFolder::class, \AvalynxOfficeEas\Db\Model\EmailAddressLink::class, \AvalynxOfficeEas\Db\Model\EmailBodyLink::class, \AvalynxOfficeEas\Db\Model\EmailAttachmentLink::class] as $linkClass) {
            foreach ($this->entityManager->getRepository($linkClass)->findBy(['email' => $email]) as $link) {
                $this->entityManager->remove($link);
            }
        }
    }

    /**
     * @return list<Email>
     */
    private function emailsForFolder(Mailbox $mailbox, Folder $folder): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Email::class, 'e')
            ->join('e.emailFolders', 'ef')
            ->where('ef.folder = :folder')
            ->andWhere('e.mailbox = :mailbox')
            ->setParameter('folder', $folder)
            ->setParameter('mailbox', $mailbox)
            ->getQuery();

        /** @var list<Email> $result */
        $result = $query->getResult();

        return $result;
    }
}
