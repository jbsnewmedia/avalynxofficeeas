<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Licensing\LicenseState as LicensingLicenseState;
use AvalynxOfficeEas\Service\ContactMapper;
use AvalynxOfficeEas\Service\ContactService;
use AvalynxOfficeEas\Service\IdnService;
use AvalynxOfficeEas\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guard rails of the contact mutation service.
 */
#[Group('integration')]
final class ContactServiceTest extends TestCase
{
    use TestEnvironment;

    protected function setUp(): void
    {
        self::bootEnvironment();
    }

    protected function tearDown(): void
    {
        self::tearDownEnvironment();
    }

    public function testUpdateRejectsAddressOutsideTheSynchronizedAccount(): void
    {
        $em = self::em();
        $seed = self::seed();

        $stranger = new Account();
        $stranger->setName('Stranger');
        $stranger->setEmail('stranger@example.com');
        $folder = $em->find(\AvalynxOfficeEas\Db\Model\Folder::class, $seed['folder']->getId());
        self::assertNotNull($folder);
        $stranger->setInboxFolder($folder);
        $em->persist($stranger);

        // A contact linked only to another account must never be writable
        // through this account's EAS scope.
        $address = new Address();
        $address->setEmail('contact@example.com');
        $address->setIsContact(true);
        $em->persist($address);
        $em->persist(new \AvalynxOfficeEas\Db\Model\AccountAddress()->setAccount($stranger)->setAddress($address));
        $em->flush();

        $service = new ContactService(
            $em,
            new ContactMapper(new IdnService()),
            new LicensingLicenseState($em->getConnection()),
        );

        $contact = new \SyncContact();
        $contact->firstname = 'Escalation';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Contact is not linked to the synchronized account.');

        $service->update($seed['account'], $address, $contact);
    }

    public function testDeleteWithoutAccountLinkChangesNothing(): void
    {
        $em = self::em();
        $seed = self::seed();

        $stranger = new Account();
        $stranger->setName('Stranger');
        $stranger->setEmail('stranger@example.com');
        $em->persist($stranger);

        $address = new Address();
        $address->setEmail('contact@example.com');
        $address->setIsContact(true);
        $em->persist($address);
        $em->persist(new \AvalynxOfficeEas\Db\Model\AccountAddress()->setAccount($stranger)->setAddress($address));
        $em->flush();

        $service = new ContactService(
            $em,
            new ContactMapper(new IdnService()),
            new LicensingLicenseState($em->getConnection()),
        );

        // No link to the synchronized account: the delete is a no-op.
        $service->delete($seed['account'], $address);

        self::em()->clear();
        $fresh = self::em()->find(Address::class, $address->getId());
        self::assertNotNull($fresh);
        $links = self::em()->getRepository(\AvalynxOfficeEas\Db\Model\AccountAddress::class)->findBy(['address' => $fresh]);
        self::assertCount(1, $links);
        self::assertSame($stranger->getId()?->toRfc4122(), $links[0]->getAccount()?->getId()?->toRfc4122());
    }

    public function testDeleteKeepsAddressLinkedToAnotherAccount(): void
    {
        $em = self::em();
        $seed = self::seed();

        $stranger = new Account();
        $stranger->setName('Stranger');
        $stranger->setEmail('stranger@example.com');
        $em->persist($stranger);

        $address = new Address();
        $address->setEmail('contact@example.com');
        $address->setIsContact(true);
        $em->persist($address);
        $em->persist(new \AvalynxOfficeEas\Db\Model\AccountAddress()->setAccount($seed['account'])->setAddress($address));
        $em->persist(new \AvalynxOfficeEas\Db\Model\AccountAddress()->setAccount($stranger)->setAddress($address));
        $em->flush();

        $service = new ContactService(
            $em,
            new ContactMapper(new IdnService()),
            new LicensingLicenseState($em->getConnection()),
        );

        $service->delete($seed['account'], $address);

        self::em()->clear();
        $fresh = self::em()->find(Address::class, $address->getId());
        self::assertNotNull($fresh);

        $remaining = self::em()->getRepository(\AvalynxOfficeEas\Db\Model\AccountAddress::class)->findBy(['address' => $fresh]);
        self::assertCount(1, $remaining);
        self::assertSame($stranger->getId()?->toRfc4122(), $remaining[0]->getAccount()?->getId()?->toRfc4122());
    }
}
