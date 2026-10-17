<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\UuidV7;

/**
 * DBAL type for Symfony UuidV7 values stored in the core's uuid columns.
 */
final class UuidV7Type extends Type
{
    public const string NAME = 'uuid_v7';

    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
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

        return is_scalar($value) ? (string) $value : null;
    }

    #[\Override]
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['uuid'];
    }
}
