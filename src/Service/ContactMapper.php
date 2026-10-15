<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Address;

/**
 * Maps Address models to EAS SyncContact objects and back.
 */
final class ContactMapper
{
    /**
     * Internal placeholder for device-created contacts without an email
     * address; it is never exported back to clients.
     */
    public const string FALLBACK_EMAIL = 'unknown@localhost';

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

        if (self::FALLBACK_EMAIL !== $address->getEmail()) {
            $contact->email1address = $address->getEmail();
        }
        $contact->firstname = $address->getFirstname();
        $contact->lastname = $address->getLastname();
        $contact->companyname = $address->getCompany();
        $contact->businessphonenumber = $address->getBusinessPhone();
        $contact->mobilephonenumber = $address->getMobilePhone();
        $contact->homephonenumber = $address->getHomePhone();

        return $contact;
    }

    public function applySyncContact(Address $address, \SyncContact $contact): void
    {
        if (false !== ($value = $this->stringOrNull($contact->firstname ?? null))) {
            $address->setFirstname($value);
        }

        if (false !== ($value = $this->stringOrNull($contact->lastname ?? null))) {
            $address->setLastname($value);
        }

        if (false !== ($value = $this->stringOrNull($contact->companyname ?? null))) {
            $address->setCompany($value);
        }

        $email = $this->extractEmail($contact->email1address ?? null);

        if (null !== $email) {
            $address->setEmail($email);
        }

        if (false !== ($value = $this->stringOrNull($contact->businessphonenumber ?? null))) {
            $address->setBusinessPhone($value);
        }

        if (false !== ($value = $this->stringOrNull($contact->mobilephonenumber ?? null))) {
            $address->setMobilePhone($value);
        }

        if (false !== ($value = $this->stringOrNull($contact->homephonenumber ?? null))) {
            $address->setHomePhone($value);
        }

        if (false !== ($value = $this->stringOrNull($contact->fileas ?? null))) {
            $address->setName($value);
        }
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
}
