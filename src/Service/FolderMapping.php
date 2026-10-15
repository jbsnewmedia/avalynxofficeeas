<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 *
 * Encodes and decodes EAS folder server ids and maps mail folders to EAS
 * folder types. Ported from the AvalynxOffice core and relicensed AGPL-3.0
 * by the copyright holder for this project.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\FolderFunction;
use AvalynxOfficeEas\Dto\EasServerId;
use Symfony\Component\Uid\UuidV7;

/**
 * Encodes and decodes EAS folder server ids and maps mail folders to EAS folder types.
 */
final class FolderMapping
{
    public const string WASTE_BASKET_NAME = 'Papierkorb';
    public const string CONTACTS_NAME = 'Kontakte';
    public const string CALENDAR_NAME = 'Kalender';
    private const string ID_PREFIX = 'mail';
    private const string CONTACTS_PREFIX = 'contacts';
    private const string CALENDAR_PREFIX = 'calendar';
    private const string SEPARATOR = '|';

    /**
     * @return array<string, list<int>>
     */
    private static function typeMap(): array
    {
        return [
            FolderFunction::INBOX->value => [SYNC_FOLDER_TYPE_INBOX],
            FolderFunction::DRAFTS->value => [SYNC_FOLDER_TYPE_DRAFTS],
            FolderFunction::OUTBOX->value => [SYNC_FOLDER_TYPE_OUTBOX],
            FolderFunction::MAILBOX->value => [SYNC_FOLDER_TYPE_USER_MAIL],
        ];
    }

    public function folderServerId(UuidV7 $mailboxId, UuidV7 $folderId): string
    {
        return base64_encode(self::ID_PREFIX.self::SEPARATOR.$mailboxId->toRfc4122().self::SEPARATOR.$folderId->toRfc4122());
    }

    public function contactServerId(UuidV7 $mailboxId): string
    {
        return base64_encode(self::CONTACTS_PREFIX.self::SEPARATOR.$mailboxId->toRfc4122());
    }

    public function decodeContact(string $serverId): ?UuidV7
    {
        return $this->decodeScopedId($serverId, self::CONTACTS_PREFIX);
    }

    public function calendarServerId(UuidV7 $mailboxId): string
    {
        return base64_encode(self::CALENDAR_PREFIX.self::SEPARATOR.$mailboxId->toRfc4122());
    }

    public function decodeCalendar(string $serverId): ?UuidV7
    {
        return $this->decodeScopedId($serverId, self::CALENDAR_PREFIX);
    }

    private function decodeScopedId(string $serverId, string $prefix): ?UuidV7
    {
        $raw = base64_decode($serverId, true);

        if (false === $raw) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $raw);

        if (2 !== count($parts) || $prefix !== $parts[0]) {
            return null;
        }

        try {
            return UuidV7::fromString($parts[1]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function decode(string $serverId): ?EasServerId
    {
        $raw = base64_decode($serverId, true);

        if (false === $raw) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $raw);

        if (3 !== count($parts) || self::ID_PREFIX !== $parts[0]) {
            return null;
        }

        try {
            return new EasServerId(UuidV7::fromString($parts[1]), UuidV7::fromString($parts[2]));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function easType(Folder $folder): int
    {
        if (self::WASTE_BASKET_NAME === $folder->getName()) {
            return SYNC_FOLDER_TYPE_WASTEBASKET;
        }

        foreach ($folder->getFunctions() as $function) {
            $type = self::typeMap()[$function] ?? null;

            if (null !== $type) {
                return $type[0];
            }
        }

        return SYNC_FOLDER_TYPE_USER_MAIL;
    }
}
