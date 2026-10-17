<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db;

use AvalynxOfficeEas\Db\Type\UuidV7Type;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;

/**
 * Creates the Doctrine EntityManager for the plugin's database models.
 *
 * The models map the AvalynxOffice database schema (the schema is the
 * interface between the two projects); no AvalynxOffice core code is involved.
 */
final class Database
{
    private function __construct()
    {
    }

    public static function entityManager(?string $databaseUrl = null): EntityManagerInterface
    {
        $databaseUrl ??= self::env('DATABASE_URL');

        if (null === $databaseUrl || '' === trim($databaseUrl)) {
            throw new \RuntimeException('DATABASE_URL is not configured.');
        }

        if (!Type::hasType(UuidV7Type::NAME)) {
            Type::addType(UuidV7Type::NAME, UuidV7Type::class);
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__).'/Db/Model'],
            true,
        );

        // Symfony 8 removed the LazyGhost generator; native lazy objects
        // (PHP 8.4+) are the supported path, same as the core.
        $config->enableNativeLazyObjects(true);

        $connection = self::connectionFromUrl($databaseUrl, $config);

        return new EntityManager($connection, $config);
    }

    /**
     * DBAL 4 no longer parses DSNs inside DriverManager; this centralizes
     * the scheme mapping (also used by the test environment bootstrap).
     */
    public static function connectionFromUrl(string $url, ?\Doctrine\DBAL\Configuration $config = null): \Doctrine\DBAL\Connection
    {
        $parser = new DsnParser([
            'postgresql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'pdo-pgsql' => 'pdo_pgsql',
            'mysql' => 'pdo_mysql',
            'mysql2' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            'sqlite3' => 'pdo_sqlite',
        ]);

        return DriverManager::getConnection($parser->parse($url), $config);
    }

    public static function env(string $name, ?string $default = null): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            $value = $source[$name] ?? null;

            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
