<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Support;

use AvalynxOfficeEas\Db\Database;
use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\AccountAddress;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\Body;
use AvalynxOfficeEas\Db\Model\BodyContentType;
use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailBodyLink;
use AvalynxOfficeEas\Db\Model\EmailFolder;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\FolderAccount;
use AvalynxOfficeEas\Db\Model\FolderFunction;
use AvalynxOfficeEas\Db\Model\LicenseState;
use AvalynxOfficeEas\Db\Model\User;
use AvalynxOfficeEas\Db\Model\UserAccount;
use AvalynxOfficeEas\Service\EndpointService;
use AvalynxOfficeEas\Service\ServiceHolder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Boots the plugin against a dedicated test database (avalynxofficeeas_test)
 * with a schema created from the plugin's own models, and seeds fixtures.
 */
trait TestEnvironment
{
    private static ?EntityManagerInterface $em = null;

    protected static function entityManager(): EntityManagerInterface
    {
        return self::$em ??= Database::entityManager(self::testDatabaseUrl());
    }

    protected static function em(): EntityManagerInterface
    {
        return self::entityManager();
    }

    protected static function testDatabaseUrl(): ?string
    {
        $url = Database::env('DATABASE_URL');

        if (null === $url) {
            return null;
        }

        // Dedicated test database so the core's database is never touched.
        return preg_replace('#/[^/?]+(\?|$)#', '/avalynxofficeeas_test$1', $url) ?? $url;
    }

    protected static function bootEnvironment(): void
    {
        $url = self::testDatabaseUrl();

        if (null === $url) {
            self::markTestSkipped('DATABASE_URL is not configured.');
        }

        self::createTestDatabase($url);
        self::refreshDatabase();
        ServiceHolder::bootstrap(dirname(__DIR__, 2), self::$em);
        EndpointService::resetZPushStatics();
    }

    protected static function tearDownEnvironment(): void
    {
        ServiceHolder::reset();
        EndpointService::resetZPushStatics();
        self::$em = null;
    }

    private static function createTestDatabase(string $url): void
    {
        // The CREATE DATABASE statement must run outside a transaction and
        // against a maintenance database. Prefer the PostgreSQL default
        // database, but fall back to the database from the URL: minimal
        // stacks (POSTGRES_DB set to something else) may not have one.
        $candidates = [
            preg_replace('#/[^/?]+(\?|$)#', '/postgres$1', $url),
            $url,
        ];

        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }

            try {
                $connection = Database::connectionFromUrl($candidate);
                $connection->executeStatement('CREATE DATABASE avalynxofficeeas_test');
                $connection->close();

                return;
            } catch (\Throwable) {
                // try the next candidate / database already exists
            }
        }
    }

    protected static function refreshDatabase(): void
    {
        $em = self::entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->clear();
    }

    /**
     * Seeds: user (password "admin") + account with an inbox folder, one
     * unread email, one contact and one calendar event, COMMUNITY license.
     *
     * @return array{user: User, account: Account, folder: Folder, email: Email, contact: Address, event: CalendarEvent}
     */
    protected static function seed(): array
    {
        $em = self::entityManager();

        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($user);

        $folder = new Folder();
        $folder->setName('Posteingang');
        $folder->setFunctions([FolderFunction::INBOX->value]);
        $em->persist($folder);

        $account = new Account();
        $account->setName('Info');
        $account->setEmail('info@example.com');
        $account->setInboxFolder($folder);
        $em->persist($account);

        $em->persist(new UserAccount()->setUser($user)->setAccount($account));
        $em->persist(new FolderAccount()->setAccount($account)->setFolder($folder)->setSortOrder(0));

        $email = new Email();
        $email->setSubject('Incoming message');
        $email->setPreviewBody('Body');
        $email->setReceivedAt(new \DateTimeImmutable('-1 hour'));
        $email->setAccount($account);
        $emailFolder = new EmailFolder();
        $emailFolder->setFolder($folder);
        $email->addEmailFolder($emailFolder);
        $em->persist($email);

        $body = new Body();
        $body->setContent('Body');
        $em->persist($body);
        $bodyLink = new EmailBodyLink();
        $bodyLink->setBody($body);
        $bodyLink->setContentType(BodyContentType::TEXT);
        $email->addEmailBody($bodyLink);
        $em->persist($bodyLink);

        $contact = new Address();
        $contact->setEmail('contact@example.com');
        $contact->setName('Contact');
        $contact->setIsContact(true);
        $em->persist($contact);
        $em->persist(new AccountAddress()->setAccount($account)->setAddress($contact));

        $event = new CalendarEvent();
        $event->setTitle('Review');
        $event->setStartAt(new \DateTimeImmutable('+1 day'));
        $event->setAccount($account);
        $em->persist($event);

        $state = new LicenseState();
        $state->setLastKnownStatus('COMMUNITY');
        $em->persist($state);

        $em->flush();
        $em->clear();

        /** @var Account $freshAccount */
        $freshAccount = $em->find(Account::class, $account->getId());

        return [
            'user' => $user,
            'account' => $freshAccount,
            'folder' => $folder,
            'email' => $email,
            'contact' => $contact,
            'event' => $event,
        ];
    }

    protected static function setLicenseStatus(string $status): void
    {
        $em = self::entityManager();
        $state = $em->find(LicenseState::class, 1);
        \assert(null !== $state);
        $state->setLastKnownStatus($status);
        $em->flush();
    }
}
