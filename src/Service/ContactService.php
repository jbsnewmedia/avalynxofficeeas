<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Db\Model\MailboxAddress;
use AvalynxOfficeEas\Licensing\LicenseState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and mutates the contacts that are exposed to EAS clients.
 *
 * Contacts are addresses flagged as contacts and linked to the synchronized
 * mailbox; the underlying address pool stays shared with the mail module.
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
    public function contactsForMailbox(Mailbox $mailbox): array
    {
        $contacts = [];

        foreach ($this->addressLinksFor($mailbox) as $link) {
            $address = $this->entityManager->find(Address::class, $link['address_id']);

            if (null !== $address && $address->isContact()) {
                $contacts[] = $address;
            }
        }

        usort($contacts, static fn (Address $left, Address $right): int => strcasecmp($left->displayName(), $right->displayName()));

        return $contacts;
    }

    public function contactForMailbox(Mailbox $mailbox, string $addressId): ?Address
    {
        foreach ($this->contactsForMailbox($mailbox) as $address) {
            if ($address->getId()?->toRfc4122() === strtolower($addressId)) {
                return $address;
            }
        }

        return null;
    }

    public function create(Mailbox $mailbox, \SyncContact $contact): Address
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');
        $address = new Address();
        $address->setEmail(ContactMapper::FALLBACK_EMAIL);
        $this->contactMapper->applySyncContact($address, $contact);
        $address->setIsContact(true);

        $this->linkAddress($mailbox, $address);

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
        // The mailbox links are not cascade-removed with the address; load
        // them explicitly so the rows and the in-memory state stay in sync.
        foreach ($this->entityManager->getRepository(MailboxAddress::class)->findBy(['address' => $address]) as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->remove($address);
        $this->entityManager->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addressLinksFor(Mailbox $mailbox): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT address_id FROM mailbox_address WHERE mailbox_id = :mailboxId',
            ['mailboxId' => $mailbox->getId()?->toRfc4122()],
        );
    }

    private function linkAddress(Mailbox $mailbox, Address $address): void
    {
        $link = new MailboxAddress();
        $link->setMailbox($mailbox);
        $link->setAddress($address);
        $this->entityManager->persist($link);
    }
}
