<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\AccountAddress;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Licensing\LicenseState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and mutates the contacts that are exposed to EAS clients.
 *
 * Contacts are addresses flagged as contacts and linked to the synchronized
 * account; the underlying address pool stays shared with the mail module.
 */
final readonly class ContactService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactMapper $contactMapper,
        private LicenseState $licenseState,
    ) {
    }

    /**
     * @return Address[]
     */
    public function contactsForAccount(Account $account): array
    {
        $contacts = [];

        foreach ($this->addressLinksFor($account) as $link) {
            $address = $this->entityManager->find(Address::class, $link['address_id']);

            if (null !== $address && $address->isContact()) {
                $contacts[] = $address;
            }
        }

        usort($contacts, static fn (Address $left, Address $right): int => strcasecmp($left->displayName(), $right->displayName()));

        return $contacts;
    }

    public function contactForAccount(Account $account, string $addressId): ?Address
    {
        foreach ($this->contactsForAccount($account) as $address) {
            if ($address->getId()?->toRfc4122() === strtolower($addressId)) {
                return $address;
            }
        }

        return null;
    }

    public function create(Account $account, \SyncContact $contact): Address
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');
        $address = new Address();
        $address->setEmail(ContactMapper::FALLBACK_EMAIL);
        $this->contactMapper->applySyncContact($address, $contact);
        $address->setIsContact(true);

        $this->linkAddress($account, $address);

        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $address;
    }

    public function update(Address $address, \SyncContact $contact): void
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');
        $this->contactMapper->applySyncContact($address, $contact);
        $this->entityManager->flush();
    }

    public function delete(Address $address): void
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');
        // The account links are not cascade-removed with the address; load
        // them explicitly so the rows and the in-memory state stay in sync.
        foreach ($this->entityManager->getRepository(AccountAddress::class)->findBy(['address' => $address]) as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->remove($address);
        $this->entityManager->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addressLinksFor(Account $account): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT address_id FROM account_address WHERE account_id = :accountId',
            ['accountId' => $account->getId()?->toRfc4122()],
        );
    }

    private function linkAddress(Account $account, Address $address): void
    {
        $link = new AccountAddress();
        $link->setAccount($account);
        $link->setAddress($address);
        $this->entityManager->persist($link);
    }
}
