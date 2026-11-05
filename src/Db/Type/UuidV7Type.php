<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Type;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\UuidV7;

/**
 * DBAL type for Symfony UuidV7 values stored in the core's uuid columns.
 *
 * Mirrors the core's portable "uuid" type: RFC-9562 strings on every
 * platform. PostgreSQL keeps its native uuid column, MariaDB/MySQL get
 * CHAR(36). The written format and the declaration must match the core
 * exactly - the database schema is the interface between the two projects.
 */
final class UuidV7Type extends Type
{
    public const string NAME = 'uuid_v7';

    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return $platform->getStringTypeDeclarationSQL([
                'length' => 36,
                'fixed' => true,
            ]);
        }

        return $platform->getGuidTypeDeclarationSQL($column);
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?UuidV7
    {
        if (null === $value || $value instanceof UuidV7) {
            return $value;
        }

        $valueString = is_scalar($value) ? (string) $value : '';

        try {
            return UuidV7::fromString($valueString);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('Invalid UUID value "%s".', $valueString), 0, $exception);
        }
    }

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof UuidV7) {
            return $value->toRfc4122();
        }

        $valueString = is_scalar($value) ? (string) $value : null;

        if (null === $valueString || '' === $valueString) {
            return null;
        }

        try {
            return UuidV7::fromString($valueString)->toRfc4122();
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('Invalid UUID value "%s".', $valueString), 0, $exception);
        }
    }

    #[\Override]
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['uuid'];
    }
}
