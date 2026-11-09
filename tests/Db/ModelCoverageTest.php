<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Db;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Db\Model\CalendarEventException;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailAddressLink;
use AvalynxOfficeEas\Db\Model\EmailAddressType;
use PHPUnit\Framework\TestCase;

/**
 * Accessor coverage for the shared-schema models.
 */
final class ModelCoverageTest extends TestCase
{
    public function testAddressExposesMailLinkCollection(): void
    {
        $address = new Address();
        $address->setEmail('jane@example.com');

        self::assertCount(0, $address->getEmailAddresses());
    }

    public function testAddressDisplayNameFallsBackToNamesThenEmail(): void
    {
        $withName = new Address();
        $withName->setEmail('jane@example.com');
        $withName->setName('Display Name');
        self::assertSame('Display Name', $withName->displayName());

        $withNames = new Address();
        $withNames->setEmail('jane@example.com');
        $withNames->setFirstname('Jane');
        $withNames->setLastname('Doe');
        self::assertSame('Jane Doe', $withNames->displayName());

        $emailOnly = new Address();
        $emailOnly->setEmail('jane@example.com');
        self::assertSame('jane@example.com', $emailOnly->displayName());
    }

    public function testCalendarEventExposesIdentifierDtstampAndAccount(): void
    {
        $event = new CalendarEvent();
        $event->setTitle('Event');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $dtstamp = new \DateTimeImmutable('2026-11-09 08:00');
        $account = new Account();
        $account->setName('Info');
        $account->setEmail('info@example.com');

        self::assertNotNull($event->getId());
        self::assertNull($event->getDtstamp());
        self::assertNull($event->getAccount());

        $event->setDtstamp($dtstamp);
        $event->setAccount($account);

        self::assertSame($dtstamp, $event->getDtstamp());
        self::assertSame($account, $event->getAccount());
    }

    public function testCalendarEventExceptionExposesIdentifier(): void
    {
        $exception = new CalendarEventException();
        $exception->setExceptionStartAt(new \DateTimeImmutable('2026-11-24 09:00'));

        self::assertNotNull($exception->getId());
    }

    public function testEmailExposesLifecycleMetadataAccessors(): void
    {
        $email = new Email();
        $scheduledAt = new \DateTimeImmutable('2026-11-10 08:00');

        self::assertNull($email->getContentHash());
        self::assertFalse($email->isDraft());
        self::assertFalse($email->isSending());
        self::assertFalse($email->isSent());
        self::assertFalse($email->isLicenseHold());
        self::assertNull($email->getScheduledAt());
        self::assertNull($email->getImportKey());

        $email->setContentHash('hash');
        $email->setIsDraft(true);
        $email->setIsSending(true);
        $email->setIsSent(true);
        $email->setLicenseHold(true);
        $email->setScheduledAt($scheduledAt);
        $email->setImportKey('import-key');

        self::assertSame('hash', $email->getContentHash());
        self::assertTrue($email->isDraft());
        self::assertTrue($email->isSending());
        self::assertTrue($email->isSent());
        self::assertTrue($email->isLicenseHold());
        self::assertSame($scheduledAt, $email->getScheduledAt());
        self::assertSame('import-key', $email->getImportKey());
    }

    public function testEmailRemovesAddressLinks(): void
    {
        $email = new Email();
        $address = new Address();
        $address->setEmail('jane@example.com');

        $link = new EmailAddressLink();
        $link->setEmail($email);
        $link->setAddress($address);
        $link->setType(EmailAddressType::RECIPIENT);
        $email->addEmailAddress($link);

        self::assertCount(1, $email->getEmailAddresses());

        $email->removeEmailAddress($link);

        self::assertCount(0, $email->getEmailAddresses());
    }
}
