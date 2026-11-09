<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Service\ContactMapper;
use AvalynxOfficeEas\Service\IdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Full-field round trips between Address models and SyncContact objects.
 */
final class ContactMapperTest extends TestCase
{
    protected function setUp(): void
    {
        // The mapper branches on the negotiated AS version; emulate a
        // modern device header since there is no request processor.
        self::setProtocolVersion('14.1');
    }

    protected function tearDown(): void
    {
        self::setProtocolVersion(null);
    }

    public function testBuildMapsEveryStoredField(): void
    {
        $contact = new ContactMapper(new IdnService())->buildSyncContact(self::filledAddress());

        self::assertSame('Doe Jane', $contact->fileas);
        self::assertSame('jane@example.com', $contact->email1address);
        self::assertSame('jane2@example.com', $contact->email2address);
        self::assertSame('jane3@example.com', $contact->email3address);
        self::assertSame('Jane', $contact->firstname);
        self::assertSame('Doe', $contact->lastname);
        self::assertSame('Ann', $contact->middlename);
        self::assertSame('JD', $contact->nickname);
        self::assertSame('Dr.', $contact->title);
        self::assertSame('PhD', $contact->suffix);
        self::assertSame('ACME Corp', $contact->companyname);
        self::assertSame('Engineering', $contact->department);
        self::assertSame('Developer', $contact->jobtitle);
        self::assertSame('Building 4', $contact->officelocation);
        self::assertSame('Nora Helper', $contact->assistantname);
        self::assertSame('Big Boss', $contact->managername);
        self::assertSame('John Doe', $contact->spouse);
        self::assertSame('https://example.com', $contact->webpage);
        self::assertSame('+49 30 111', $contact->businessphonenumber);
        self::assertSame('+49 30 112', $contact->business2phonenumber);
        self::assertSame('+49 30 113', $contact->businessfaxnumber);
        self::assertSame('+49 170 111', $contact->mobilephonenumber);
        self::assertSame('+49 40 111', $contact->homephonenumber);
        self::assertSame('+49 40 112', $contact->home2phonenumber);
        self::assertSame('+49 40 113', $contact->homefaxnumber);
        self::assertSame('+49 170 222', $contact->carphonenumber);
        self::assertSame('+49 170 333', $contact->pagernumber);
        self::assertSame('+49 170 444', $contact->radiophonenumber);
        self::assertSame('+49 30 114', $contact->assistnamephonenumber);
        self::assertSame('+49 30 115', $contact->companymainphone);
        self::assertSame('+49 170 555', $contact->mms);
        self::assertSame('jane@im.example.com', $contact->imaddress);
        self::assertSame('jane2@im.example.com', $contact->imaddress2);
        self::assertSame('jane3@im.example.com', $contact->imaddress3);
        self::assertSame('C-4711', $contact->customerid);
        self::assertSame('G-0815', $contact->governmentid);
        self::assertSame('A-1', $contact->accountname);
        self::assertSame('ACME Kana', $contact->yomicompanyname);
        self::assertSame('Jane Kana', $contact->yomifirstname);
        self::assertSame('Doe Kana', $contact->yomilastname);
        self::assertSame(new \DateTimeImmutable('1990-05-14 12:00')->getTimestamp(), $contact->birthday);
        self::assertSame(new \DateTimeImmutable('2015-06-20 12:00')->getTimestamp(), $contact->anniversary);
        self::assertSame('Business Street 1', $contact->businessstreet);
        self::assertSame('Berlin', $contact->businesscity);
        self::assertSame('Berlin', $contact->businessstate);
        self::assertSame('10115', $contact->businesspostalcode);
        self::assertSame('Germany', $contact->businesscountry);
        self::assertSame('Home Street 2', $contact->homestreet);
        self::assertSame('Hamburg', $contact->homecity);
        self::assertSame('Hamburg', $contact->homestate);
        self::assertSame('20095', $contact->homepostalcode);
        self::assertSame('Germany', $contact->homecountry);
        self::assertSame('Other Street 3', $contact->otherstreet);
        self::assertSame('Köln', $contact->othercity);
        self::assertSame('NRW', $contact->otherstate);
        self::assertSame('50667', $contact->otherpostalcode);
        self::assertSame('Germany', $contact->othercountry);
        self::assertSame(['Max', 'Moritz'], $contact->children);
        self::assertSame(['Friends', 'Work'], $contact->categories);
        self::assertSame('aGVsbG8=', $contact->picture);

        self::assertInstanceOf(\SyncBaseBody::class, $contact->asbody);
        self::assertIsResource($contact->asbody->data);
        self::assertSame(SYNC_BODYPREFERENCE_PLAIN, $contact->asbody->type);
        self::assertSame('Note text', (string) stream_get_contents($contact->asbody->data));
    }

    public function testBuildUsesLegacyBodyBelowProtocol12(): void
    {
        self::setProtocolVersion('2.5');

        $contact = new ContactMapper(new IdnService())->buildSyncContact(self::filledAddress());

        self::assertSame('Note text', $contact->body);
        self::assertSame(strlen('Note text'), $contact->bodysize);
        self::assertSame(0, $contact->bodytruncated);
        self::assertNull($contact->asbody);
    }

    public function testBuildOmitsFallbackEmailAndEmptyFields(): void
    {
        $address = new Address();
        $address->setEmail(ContactMapper::FALLBACK_EMAIL);
        $address->setName('Nobody');

        $contact = new ContactMapper(new IdnService())->buildSyncContact($address);

        self::assertSame('Nobody', $contact->fileas);
        self::assertNull($contact->email1address);
        self::assertNull($contact->firstname);
        self::assertNull($contact->birthday);
        self::assertNull($contact->picture);
    }

    public function testApplyRoundTripsEveryField(): void
    {
        $mapper = new ContactMapper(new IdnService());
        $expected = self::filledAddress();
        $syncContact = $mapper->buildSyncContact($expected);
        $applied = new Address();
        $applied->setEmail('seed@example.com');

        $mapper->applySyncContact($applied, $syncContact);

        self::assertSame($expected->getEmail(), $applied->getEmail());
        self::assertSame($expected->getEmail2(), $applied->getEmail2());
        self::assertSame($expected->getEmail3(), $applied->getEmail3());
        self::assertSame($expected->getFirstname(), $applied->getFirstname());
        self::assertSame($expected->getLastname(), $applied->getLastname());
        self::assertSame($expected->getMiddlename(), $applied->getMiddlename());
        self::assertSame($expected->getNickname(), $applied->getNickname());
        self::assertSame($expected->getNamePrefix(), $applied->getNamePrefix());
        self::assertSame($expected->getNameSuffix(), $applied->getNameSuffix());
        self::assertSame($expected->getCompany(), $applied->getCompany());
        self::assertSame($expected->getDepartment(), $applied->getDepartment());
        self::assertSame($expected->getJobTitle(), $applied->getJobTitle());
        self::assertSame($expected->getOfficeLocation(), $applied->getOfficeLocation());
        self::assertSame($expected->getAssistantName(), $applied->getAssistantName());
        self::assertSame($expected->getManagerName(), $applied->getManagerName());
        self::assertSame($expected->getSpouseName(), $applied->getSpouseName());
        self::assertSame($expected->getWebsite(), $applied->getWebsite());
        self::assertSame($expected->getBusinessPhone(), $applied->getBusinessPhone());
        self::assertSame($expected->getBusinessPhone2(), $applied->getBusinessPhone2());
        self::assertSame($expected->getBusinessFaxNumber(), $applied->getBusinessFaxNumber());
        self::assertSame($expected->getMobilePhone(), $applied->getMobilePhone());
        self::assertSame($expected->getHomePhone(), $applied->getHomePhone());
        self::assertSame($expected->getHomePhone2(), $applied->getHomePhone2());
        self::assertSame($expected->getHomeFaxNumber(), $applied->getHomeFaxNumber());
        self::assertSame($expected->getCarPhone(), $applied->getCarPhone());
        self::assertSame($expected->getPagerNumber(), $applied->getPagerNumber());
        self::assertSame($expected->getRadioNumber(), $applied->getRadioNumber());
        self::assertSame($expected->getAssistantPhone(), $applied->getAssistantPhone());
        self::assertSame($expected->getCompanyPhone(), $applied->getCompanyPhone());
        self::assertSame($expected->getMms(), $applied->getMms());
        self::assertSame($expected->getImAddress(), $applied->getImAddress());
        self::assertSame($expected->getImAddress2(), $applied->getImAddress2());
        self::assertSame($expected->getImAddress3(), $applied->getImAddress3());
        self::assertSame($expected->getCustomerId(), $applied->getCustomerId());
        self::assertSame($expected->getGovernmentId(), $applied->getGovernmentId());
        self::assertSame($expected->getAccountName(), $applied->getAccountName());
        self::assertSame($expected->getYomiCompany(), $applied->getYomiCompany());
        self::assertSame($expected->getYomiFirstname(), $applied->getYomiFirstname());
        self::assertSame($expected->getYomiLastname(), $applied->getYomiLastname());
        self::assertSame('1990-05-14', $applied->getBirthday()?->format('Y-m-d'));
        self::assertSame('2015-06-20', $applied->getAnniversary()?->format('Y-m-d'));
        self::assertSame($expected->getBusinessStreet(), $applied->getBusinessStreet());
        self::assertSame($expected->getBusinessCity(), $applied->getBusinessCity());
        self::assertSame($expected->getBusinessState(), $applied->getBusinessState());
        self::assertSame($expected->getBusinessPostalCode(), $applied->getBusinessPostalCode());
        self::assertSame($expected->getBusinessCountry(), $applied->getBusinessCountry());
        self::assertSame($expected->getHomeStreet(), $applied->getHomeStreet());
        self::assertSame($expected->getHomeCity(), $applied->getHomeCity());
        self::assertSame($expected->getHomeState(), $applied->getHomeState());
        self::assertSame($expected->getHomePostalCode(), $applied->getHomePostalCode());
        self::assertSame($expected->getHomeCountry(), $applied->getHomeCountry());
        self::assertSame($expected->getOtherStreet(), $applied->getOtherStreet());
        self::assertSame($expected->getOtherCity(), $applied->getOtherCity());
        self::assertSame($expected->getOtherState(), $applied->getOtherState());
        self::assertSame($expected->getOtherPostalCode(), $applied->getOtherPostalCode());
        self::assertSame($expected->getOtherCountry(), $applied->getOtherCountry());
        self::assertSame($expected->getChildren(), $applied->getChildren());
        self::assertSame($expected->getCategories(), $applied->getCategories());
        self::assertSame($expected->getPicture(), $applied->getPicture());
        self::assertSame('Note text', $applied->getNote());
        self::assertSame('Doe Jane', $applied->getName());
    }

    public function testApplyReadsLegacyBodyNote(): void
    {
        self::setProtocolVersion('2.5');

        $contact = new \SyncContact();
        $contact->body = 'Legacy note';

        $address = new Address();
        $address->setEmail('x@example.com');
        new ContactMapper(new IdnService())->applySyncContact($address, $contact);

        self::assertSame('Legacy note', $address->getNote());
    }

    public function testApplyClearsFieldsTheDeviceCleared(): void
    {
        $contact = new \SyncContact();
        $contact->jobtitle = ' ';
        $contact->birthday = 0;

        $address = self::filledAddress();
        new ContactMapper(new IdnService())->applySyncContact($address, $contact);

        self::assertNull($address->getJobTitle());
        self::assertNull($address->getBirthday());
    }

    #[DataProvider('emailNormalizationProvider')]
    public function testApplyNormalizesEmails(string $input, string $expected): void
    {
        $contact = new \SyncContact();
        $contact->email1address = $input;

        $address = new Address();
        $address->setEmail('seed@example.com');
        new ContactMapper(new IdnService())->applySyncContact($address, $contact);

        self::assertSame($expected, $address->getEmail());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function emailNormalizationProvider(): array
    {
        return [
            ['Jane <jane@bästoff.de>', 'jane@xn--bstoff-bua.de'],
            ['jane@example.com', 'jane@example.com'],
        ];
    }

    private static function filledAddress(): Address
    {
        return new Address()
            ->setEmail('jane@example.com')
            ->setName('Doe Jane')
            ->setFirstname('Jane')
            ->setLastname('Doe')
            ->setMiddlename('Ann')
            ->setNickname('JD')
            ->setNamePrefix('Dr.')
            ->setNameSuffix('PhD')
            ->setCompany('ACME Corp')
            ->setDepartment('Engineering')
            ->setJobTitle('Developer')
            ->setOfficeLocation('Building 4')
            ->setAssistantName('Nora Helper')
            ->setManagerName('Big Boss')
            ->setSpouseName('John Doe')
            ->setWebsite('https://example.com')
            ->setBusinessPhone('+49 30 111')
            ->setBusinessPhone2('+49 30 112')
            ->setBusinessFaxNumber('+49 30 113')
            ->setMobilePhone('+49 170 111')
            ->setHomePhone('+49 40 111')
            ->setHomePhone2('+49 40 112')
            ->setHomeFaxNumber('+49 40 113')
            ->setCarPhone('+49 170 222')
            ->setPagerNumber('+49 170 333')
            ->setRadioNumber('+49 170 444')
            ->setAssistantPhone('+49 30 114')
            ->setCompanyPhone('+49 30 115')
            ->setMms('+49 170 555')
            ->setEmail2('jane2@example.com')
            ->setEmail3('jane3@example.com')
            ->setImAddress('jane@im.example.com')
            ->setImAddress2('jane2@im.example.com')
            ->setImAddress3('jane3@im.example.com')
            ->setCustomerId('C-4711')
            ->setGovernmentId('G-0815')
            ->setAccountName('A-1')
            ->setYomiCompany('ACME Kana')
            ->setYomiFirstname('Jane Kana')
            ->setYomiLastname('Doe Kana')
            ->setBirthday(new \DateTimeImmutable('1990-05-14 12:00'))
            ->setAnniversary(new \DateTimeImmutable('2015-06-20 12:00'))
            ->setBusinessStreet('Business Street 1')
            ->setBusinessCity('Berlin')
            ->setBusinessState('Berlin')
            ->setBusinessPostalCode('10115')
            ->setBusinessCountry('Germany')
            ->setHomeStreet('Home Street 2')
            ->setHomeCity('Hamburg')
            ->setHomeState('Hamburg')
            ->setHomePostalCode('20095')
            ->setHomeCountry('Germany')
            ->setOtherStreet('Other Street 3')
            ->setOtherCity('Köln')
            ->setOtherState('NRW')
            ->setOtherPostalCode('50667')
            ->setOtherCountry('Germany')
            ->setNote('Note text')
            ->setChildren(['Max', 'Moritz'])
            ->setCategories(['Friends', 'Work'])
            ->setPicture('aGVsbG8=');
    }

    private static function setProtocolVersion(?string $version): void
    {
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, $version);
    }
}
