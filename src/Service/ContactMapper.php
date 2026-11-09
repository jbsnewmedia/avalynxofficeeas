<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Address;

/**
 * Maps Address models to EAS SyncContact objects and back.
 *
 * Every SyncContact field with a storage counterpart is mapped; the
 * deliberately unmapped fields are rtf (no RTF storage) and the
 * bodysize/bodytruncated legacy pair, which is derived from the note on
 * export for protocol versions below 12.
 */
final readonly class ContactMapper
{
    /**
     * Internal placeholder for device-created contacts without an email
     * address; it is never exported back to clients.
     */
    public const string FALLBACK_EMAIL = 'unknown@localhost';

    public function __construct(
        private IdnService $idnService,
    ) {
    }

    /**
     * @return array{id: string, mod: string, flags: int, star: int}
     */
    public function stat(Address $address): array
    {
        return [
            'id' => (string) $address->getId()?->toRfc4122(),
            'mod' => (string) ($address->getUpdatedAt()?->getTimestamp() ?? 0),
            'flags' => 0,
            'star' => 0,
        ];
    }

    public function buildSyncContact(Address $address): \SyncContact
    {
        $contact = new \SyncContact();
        $contact->fileas = $address->displayName();

        $this->assignEmail($contact, 'email1address', $address->getEmail(), self::FALLBACK_EMAIL);
        $this->assignEmail($contact, 'email2address', $address->getEmail2());
        $this->assignEmail($contact, 'email3address', $address->getEmail3());

        $this->assignString($contact, 'firstname', $address->getFirstname());
        $this->assignString($contact, 'lastname', $address->getLastname());
        $this->assignString($contact, 'middlename', $address->getMiddlename());
        $this->assignString($contact, 'nickname', $address->getNickname());
        $this->assignString($contact, 'title', $address->getNamePrefix());
        $this->assignString($contact, 'suffix', $address->getNameSuffix());
        $this->assignString($contact, 'companyname', $address->getCompany());
        $this->assignString($contact, 'department', $address->getDepartment());
        $this->assignString($contact, 'jobtitle', $address->getJobTitle());
        $this->assignString($contact, 'officelocation', $address->getOfficeLocation());
        $this->assignString($contact, 'assistantname', $address->getAssistantName());
        $this->assignString($contact, 'managername', $address->getManagerName());
        $this->assignString($contact, 'spouse', $address->getSpouseName());
        $this->assignString($contact, 'webpage', $address->getWebsite());

        $this->assignString($contact, 'businessphonenumber', $address->getBusinessPhone());
        $this->assignString($contact, 'business2phonenumber', $address->getBusinessPhone2());
        $this->assignString($contact, 'businessfaxnumber', $address->getBusinessFaxNumber());
        $this->assignString($contact, 'mobilephonenumber', $address->getMobilePhone());
        $this->assignString($contact, 'homephonenumber', $address->getHomePhone());
        $this->assignString($contact, 'home2phonenumber', $address->getHomePhone2());
        $this->assignString($contact, 'homefaxnumber', $address->getHomeFaxNumber());
        $this->assignString($contact, 'carphonenumber', $address->getCarPhone());
        $this->assignString($contact, 'pagernumber', $address->getPagerNumber());
        $this->assignString($contact, 'radiophonenumber', $address->getRadioNumber());
        $this->assignString($contact, 'assistnamephonenumber', $address->getAssistantPhone());
        $this->assignString($contact, 'companymainphone', $address->getCompanyPhone());
        $this->assignString($contact, 'mms', $address->getMms());

        $this->assignString($contact, 'imaddress', $address->getImAddress());
        $this->assignString($contact, 'imaddress2', $address->getImAddress2());
        $this->assignString($contact, 'imaddress3', $address->getImAddress3());
        $this->assignString($contact, 'customerid', $address->getCustomerId());
        $this->assignString($contact, 'governmentid', $address->getGovernmentId());
        $this->assignString($contact, 'accountname', $address->getAccountName());

        $this->assignString($contact, 'yomicompanyname', $address->getYomiCompany());
        $this->assignString($contact, 'yomifirstname', $address->getYomiFirstname());
        $this->assignString($contact, 'yomilastname', $address->getYomiLastname());

        if (null !== $address->getBirthday()) {
            $contact->birthday = $address->getBirthday()->getTimestamp();
        }

        if (null !== $address->getAnniversary()) {
            $contact->anniversary = $address->getAnniversary()->getTimestamp();
        }

        $this->assignString($contact, 'businessstreet', $address->getBusinessStreet());
        $this->assignString($contact, 'businesscity', $address->getBusinessCity());
        $this->assignString($contact, 'businessstate', $address->getBusinessState());
        $this->assignString($contact, 'businesspostalcode', $address->getBusinessPostalCode());
        $this->assignString($contact, 'businesscountry', $address->getBusinessCountry());
        $this->assignString($contact, 'homestreet', $address->getHomeStreet());
        $this->assignString($contact, 'homecity', $address->getHomeCity());
        $this->assignString($contact, 'homestate', $address->getHomeState());
        $this->assignString($contact, 'homepostalcode', $address->getHomePostalCode());
        $this->assignString($contact, 'homecountry', $address->getHomeCountry());
        $this->assignString($contact, 'otherstreet', $address->getOtherStreet());
        $this->assignString($contact, 'othercity', $address->getOtherCity());
        $this->assignString($contact, 'otherstate', $address->getOtherState());
        $this->assignString($contact, 'otherpostalcode', $address->getOtherPostalCode());
        $this->assignString($contact, 'othercountry', $address->getOtherCountry());

        $children = $address->getChildren();

        if (null !== $children && [] !== $children) {
            $contact->children = $children;
        }

        $categories = $address->getCategories();

        if (null !== $categories && [] !== $categories) {
            $contact->categories = $categories;
        }

        if (null !== $address->getPicture() && '' !== $address->getPicture()) {
            $contact->picture = $address->getPicture();
        }

        $this->assignNote($contact, $address->getNote());

        return $contact;
    }

    public function applySyncContact(Address $address, \SyncContact $contact): void
    {
        $strings = [
            'firstname' => 'setFirstname',
            'lastname' => 'setLastname',
            'middlename' => 'setMiddlename',
            'nickname' => 'setNickname',
            'title' => 'setNamePrefix',
            'suffix' => 'setNameSuffix',
            'companyname' => 'setCompany',
            'department' => 'setDepartment',
            'jobtitle' => 'setJobTitle',
            'officelocation' => 'setOfficeLocation',
            'assistantname' => 'setAssistantName',
            'managername' => 'setManagerName',
            'spouse' => 'setSpouseName',
            'webpage' => 'setWebsite',
            'businessphonenumber' => 'setBusinessPhone',
            'business2phonenumber' => 'setBusinessPhone2',
            'businessfaxnumber' => 'setBusinessFaxNumber',
            'mobilephonenumber' => 'setMobilePhone',
            'homephonenumber' => 'setHomePhone',
            'home2phonenumber' => 'setHomePhone2',
            'homefaxnumber' => 'setHomeFaxNumber',
            'carphonenumber' => 'setCarPhone',
            'pagernumber' => 'setPagerNumber',
            'radiophonenumber' => 'setRadioNumber',
            'assistnamephonenumber' => 'setAssistantPhone',
            'companymainphone' => 'setCompanyPhone',
            'mms' => 'setMms',
            'imaddress' => 'setImAddress',
            'imaddress2' => 'setImAddress2',
            'imaddress3' => 'setImAddress3',
            'customerid' => 'setCustomerId',
            'governmentid' => 'setGovernmentId',
            'accountname' => 'setAccountName',
            'yomicompanyname' => 'setYomiCompany',
            'yomifirstname' => 'setYomiFirstname',
            'yomilastname' => 'setYomiLastname',
            'businessstreet' => 'setBusinessStreet',
            'businesscity' => 'setBusinessCity',
            'businessstate' => 'setBusinessState',
            'businesspostalcode' => 'setBusinessPostalCode',
            'businesscountry' => 'setBusinessCountry',
            'homestreet' => 'setHomeStreet',
            'homecity' => 'setHomeCity',
            'homestate' => 'setHomeState',
            'homepostalcode' => 'setHomePostalCode',
            'homecountry' => 'setHomeCountry',
            'otherstreet' => 'setOtherStreet',
            'othercity' => 'setOtherCity',
            'otherstate' => 'setOtherState',
            'otherpostalcode' => 'setOtherPostalCode',
            'othercountry' => 'setOtherCountry',
        ];

        foreach ($strings as $property => $setter) {
            $value = $this->stringOrNull($contact->{$property} ?? null);

            if (false !== $value) {
                $address->{$setter}($value);
            }
        }

        $email = $this->extractEmail($contact->email1address ?? null);

        if (null !== $email) {
            // Device input is stored in the core's canonical punycode form.
            $address->setEmail($this->idnService->toAscii($email));
        }

        foreach (['email2address' => 'setEmail2', 'email3address' => 'setEmail3'] as $property => $setter) {
            $value = $this->extractEmail($contact->{$property} ?? null);

            if (null !== $value) {
                $address->{$setter}($this->idnService->toAscii($value));
            }
        }

        // The isset() guard distinguishes "device cleared the date" (the
        // EAS empty date decodes to timestamp 0 and clears the field)
        // from "device did not send the field at all".
        if (isset($contact->birthday)) {
            $address->setBirthday($this->dateOrNull($contact->birthday));
        }

        if (isset($contact->anniversary)) {
            $address->setAnniversary($this->dateOrNull($contact->anniversary));
        }

        $children = $this->stringListOrNull($contact->children ?? null);

        if (null !== $children) {
            $address->setChildren($children);
        }

        $categories = $this->stringListOrNull($contact->categories ?? null);

        if (null !== $categories) {
            $address->setCategories($categories);
        }

        $picture = $this->stringOrNull($contact->picture ?? null);

        if (false !== $picture) {
            $address->setPicture($picture);
        }

        $note = $this->noteOrNull($contact);

        if (false !== $note) {
            $address->setNote($note);
        }

        if (false !== ($value = $this->stringOrNull($contact->fileas ?? null))) {
            $address->setName($value);
        }
    }

    private function assignEmail(\SyncContact $contact, string $property, ?string $email, ?string $skipValue = null): void
    {
        if (null === $email || '' === $email || $email === $skipValue) {
            return;
        }

        // Stored punycode domains are decoded for the device display.
        $contact->{$property} = $this->idnService->toUnicode($email);
    }

    private function assignString(\SyncContact $contact, string $property, ?string $value): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        $contact->{$property} = $value;
    }

    /**
     * The contact note follows the message body conventions: AirSyncBase
     * body from protocol 12 on, the legacy body/bodysize/bodytruncated
     * triple below.
     */
    private function assignNote(\SyncContact $contact, ?string $note): void
    {
        if (null === $note || '' === $note) {
            return;
        }

        if (\Request::GetProtocolVersion() >= 12.0) {
            $asbody = new \SyncBaseBody();
            $asbody->type = SYNC_BODYPREFERENCE_PLAIN;
            $asbody->truncated = 0;
            $asbody->data = \StringStreamWrapper::Open($note);
            $asbody->estimatedDataSize = strlen($note);
            $contact->asbody = $asbody;

            return;
        }

        $contact->body = $note;
        $contact->bodysize = strlen($note);
        $contact->bodytruncated = 0;
    }

    /**
     * Reads the note from whichever body representation the device used.
     * Returns false when the device sent no note at all (field untouched).
     */
    private function noteOrNull(\SyncContact $contact): string|false|null
    {
        if (isset($contact->asbody) && $contact->asbody instanceof \SyncBaseBody) {
            $data = $contact->asbody->data;

            if (is_resource($data)) {
                $data = (string) stream_get_contents($data);
            }

            return is_string($data) && '' !== trim($data) ? $data : null;
        }

        if (isset($contact->body)) {
            $value = is_string($contact->body) ? trim($contact->body) : '';

            return '' !== $value ? $value : null;
        }

        return false;
    }

    /**
     * Normalizes device-provided strings: null for whitespace-only values
     * (clears the field), false for non-strings (ignored by the caller).
     */
    private function stringOrNull(mixed $value): string|false|null
    {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    /**
     * Devices may send addresses in display form ("Name <mail@host>"); only
     * the address part is stored.
     */
    private function extractEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (1 === preg_match('/<([^<>]*)>/', $value, $matches)) {
            $value = $matches[1];
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $timestamp = is_int($value) ? $value : (is_numeric($value) ? (int) $value : strtotime($value));

        if (false === $timestamp || 0 >= $timestamp) {
            return null;
        }

        // Dates are decoded as UTC timestamps; the calendar day is stored
        // at midday UTC so no timezone shift can change the stored date.
        return new \DateTimeImmutable('@'.$timestamp)->setTime(12, 0);
    }

    /**
     * @return list<string>|null null clears the list, non-arrays are ignored
     */
    private function stringListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
