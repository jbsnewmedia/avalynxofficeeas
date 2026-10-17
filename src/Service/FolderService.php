<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailFolder;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\FolderFunction;
use AvalynxOfficeEas\Db\Model\FolderMailbox;
use AvalynxOfficeEas\Db\Model\Mailbox;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Folder lifecycle for device-driven changes (create, system folder sync,
 * move). Mirrors the core's folder semantics on the shared tables.
 */
class FolderService
{
    public const FOLDER_MAILBOX = 'Postfach';
    public const FOLDER_INBOX = 'Posteingang';
    public const FOLDER_OUTBOX = 'Postausgang';
    public const FOLDER_DRAFTS = 'Entwürfe';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, array{name: string, sortOrder: int}>
     */
    public static function systemFolderDefinitions(): array
    {
        return [
            FolderFunction::MAILBOX->value => ['name' => self::FOLDER_MAILBOX, 'sortOrder' => -100],
            FolderFunction::INBOX->value => ['name' => self::FOLDER_INBOX, 'sortOrder' => -90],
            FolderFunction::OUTBOX->value => ['name' => self::FOLDER_OUTBOX, 'sortOrder' => -80],
            FolderFunction::DRAFTS->value => ['name' => self::FOLDER_DRAFTS, 'sortOrder' => -70],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function requiredSystemFunctions(): array
    {
        return array_keys(self::systemFolderDefinitions());
    }

    public static function defaultFolderName(FolderFunction|string $function): string
    {
        $normalizedFunction = self::normalizeFunctionValue($function);

        return self::systemFolderDefinitions()[$normalizedFunction]['name'] ?? self::FOLDER_MAILBOX;
    }

    public static function defaultSortOrder(FolderFunction|string $function): int
    {
        $normalizedFunction = self::normalizeFunctionValue($function);

        return self::systemFolderDefinitions()[$normalizedFunction]['sortOrder'] ?? 0;
    }

    public static function isSystemFolder(Folder $folder): bool
    {
        return [] !== array_intersect($folder->getFunctions(), self::requiredSystemFunctions());
    }

    /**
     * @param array<string|FolderFunction> $functions
     */
    public function ensureFolder(Mailbox $mailbox, string $folderName, int $sortOrder = 0, array $functions = []): Folder
    {
        $normalizedFunctions = self::normalizeFunctions($functions);
        $folders = $this->foldersForMailbox($mailbox);

        if ([] !== $normalizedFunctions) {
            foreach ($folders as $folder) {
                if ([] === array_intersect($normalizedFunctions, $folder->getFunctions())) {
                    continue;
                }

                $changed = false;
                if ($folder->getName() !== $folderName && '' !== $folderName) {
                    $folder->setName($folderName);
                    $changed = true;
                }
                if ($folder->getFunctions() !== $normalizedFunctions) {
                    $folder->setFunctions($normalizedFunctions);
                    $changed = true;
                }
                if ($this->syncMailboxFolderLink($mailbox, $folder, $sortOrder)) {
                    $changed = true;
                }
                if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true) && $mailbox->getInboxFolder() !== $folder) {
                    $mailbox->setInboxFolder($folder);
                    $this->em->persist($mailbox);
                    $changed = true;
                }
                if ($changed) {
                    $this->em->persist($folder);
                    $this->em->flush();
                }

                return $folder;
            }
        }

        foreach ($folders as $f) {
            if ($f->getName() === $folderName) {
                $changed = false;
                if ([] !== $normalizedFunctions && $f->getFunctions() !== $normalizedFunctions) {
                    $f->setFunctions($normalizedFunctions);
                    $this->em->persist($f);
                    $changed = true;
                }
                if ($this->syncMailboxFolderLink($mailbox, $f, $sortOrder)) {
                    $changed = true;
                }
                if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true) && $mailbox->getInboxFolder() !== $f) {
                    $mailbox->setInboxFolder($f);
                    $this->em->persist($mailbox);
                    $changed = true;
                }
                if ($changed) {
                    $this->em->flush();
                }

                return $f;
            }
        }

        $folder = new Folder();
        $folder->setName($folderName);
        $folder->setFunctions($normalizedFunctions);
        $this->em->persist($folder);
        $this->em->flush();
        $link = new FolderMailbox();
        $link->setMailbox($mailbox);
        $link->setFolder($folder);
        $link->setSortOrder($sortOrder);
        $this->em->persist($link);
        $this->em->flush();
        if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true)) {
            $mailbox->setInboxFolder($folder);
            $this->em->persist($mailbox);
            $this->em->flush();
        }

        return $folder;
    }

    public function ensureSystemFolder(Mailbox $mailbox, FolderFunction|string $function): Folder
    {
        $normalizedFunction = self::normalizeFunctionValue($function);
        $existing = $this->getFolderByFunction($mailbox, $normalizedFunction);
        if ($existing instanceof Folder) {
            $changed = false;
            if ($this->syncMailboxFolderLink($mailbox, $existing, self::defaultSortOrder($normalizedFunction))) {
                $changed = true;
            }
            if (FolderFunction::MAILBOX->value === $normalizedFunction && $mailbox->getInboxFolder() !== $existing) {
                $mailbox->setInboxFolder($existing);
                $this->em->persist($mailbox);
                $changed = true;
            }
            if ($changed) {
                $this->em->flush();
            }

            return $existing;
        }

        return $this->ensureFolder(
            $mailbox,
            self::defaultFolderName($normalizedFunction),
            self::defaultSortOrder($normalizedFunction),
            [$normalizedFunction],
        );
    }

    public function getFolderByFunction(Mailbox $mailbox, FolderFunction|string $function): ?Folder
    {
        $normalizedFunction = self::normalizeFunctionValue($function);
        foreach ($this->foldersForMailbox($mailbox) as $folder) {
            if ($folder->hasFunction($normalizedFunction)) {
                return $folder;
            }
        }

        return null;
    }

    public function moveEmailToFolder(Email $email, Folder $targetFolder, ?Folder $sourceFolder = null): void
    {
        $found = false;
        foreach ($email->getEmailFolders() as $ef) {
            if ($sourceFolder && $ef->getFolder() === $sourceFolder) {
                $ef->setFolder($targetFolder);
                $found = true;
            }
        }
        if (!$found) {
            $this->assignEmailToFolder($email, $targetFolder);
        }
    }

    public function syncEmailSystemFolders(Email $email): void
    {
        $mailbox = $email->getMailbox();
        if (!$mailbox instanceof Mailbox) {
            return;
        }

        $targetFunctions = $this->resolveTargetFunctions($email);
        foreach ($email->getEmailFolders()->toArray() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (!$folder instanceof Folder || !self::isSystemFolder($folder)) {
                continue;
            }

            if ([] !== array_intersect($folder->getFunctions(), $targetFunctions)) {
                continue;
            }

            $email->removeEmailFolder($emailFolder);
            $this->em->remove($emailFolder);
        }

        foreach ($targetFunctions as $function) {
            $folder = $this->ensureSystemFolder($mailbox, $function);
            $this->assignEmailToFolder($email, $folder);
        }
    }

    public function assignEmailToFolder(Email $email, Folder $folder): void
    {
        foreach ($email->getEmailFolders() as $ef) {
            if ($ef->getFolder() === $folder) {
                return;
            }
        }
        $ef = new EmailFolder();
        $ef->setEmail($email);
        $ef->setFolder($folder);
        $this->em->persist($ef);
        $email->addEmailFolder($ef);
    }

    /**
     * @return Folder[]
     */
    private function foldersForMailbox(Mailbox $mailbox): array
    {
        /** @var list<Folder> $result */
        $result = $this->em->createQueryBuilder()
            ->select('f')
            ->from(Folder::class, 'f')
            ->join(FolderMailbox::class, 'fl', 'WITH', 'fl.folder = f')
            ->where('fl.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->orderBy('fl.sortOrder', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @param array<string|FolderFunction> $functions
     *
     * @return list<string>
     */
    public static function normalizeFunctions(array $functions): array
    {
        $normalized = [];
        foreach ($functions as $function) {
            $value = self::normalizeFunctionValue($function);
            if ('' === $value || 'none' === $value) {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeFunctionValue(FolderFunction|string $function): string
    {
        $value = strtolower(trim($function instanceof FolderFunction ? $function->value : $function));

        return 'sent' === $value ? FolderFunction::OUTBOX->value : $value;
    }

    private function syncMailboxFolderLink(Mailbox $mailbox, Folder $folder, int $sortOrder): bool
    {
        foreach ($mailbox->getFolderLinks() as $link) {
            if ($link->getFolder() !== $folder) {
                continue;
            }

            if ($link->getSortOrder() === $sortOrder) {
                return false;
            }

            $link->setSortOrder($sortOrder);
            $this->em->persist($link);

            return true;
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function resolveTargetFunctions(Email $email): array
    {
        if (!$email->isOutgoing() && $email->getReceivedAt() instanceof \DateTimeImmutable) {
            return [FolderFunction::MAILBOX->value, FolderFunction::INBOX->value];
        }

        if ($email->isDraft() || (!$email->isOutgoing() && null === $email->getReceivedAt())) {
            return [FolderFunction::DRAFTS->value];
        }

        return [FolderFunction::OUTBOX->value];
    }
}
