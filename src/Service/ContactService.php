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
use AvalynxOfficeEas\Db\Model\EmailAddressLink;
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

    public function update(Account $account, Address $address, \SyncContact $contact): Address
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');

        $accountLinkRepository = $this->entityManager->getRepository(AccountAddress::class);
        $accountLink = $accountLinkRepository->findOneBy(['account' => $account, 'address' => $address]);
        if (!$accountLink instanceof AccountAddress) {
            throw new \LogicException('Contact is not linked to the synchronized account.');
        }

        // Copy-on-write: an address row that is also referenced by another
        // account (contact link or e-mail of that account) must never be
        // modified in place, otherwise this account could rewrite the
        // e-mail address shown in other users' mails.
        if ($this->isSharedWithOtherAccounts($account, $address)) {
            $address = $this->copyContact($address);
            $this->entityManager->persist($address);
            $this->entityManager->remove($accountLink);
            $this->linkAddress($account, $address);
        }

        $this->contactMapper->applySyncContact($address, $contact);
        $this->entityManager->flush();

        return $address;
    }

    public function delete(Account $account, Address $address): void
    {
        $this->licenseState->assertMutationAllowed('CONTACT_WRITE');

        $accountLinkFound = false;
        $otherAccountLinkFound = false;
        foreach ($this->entityManager->getRepository(AccountAddress::class)->findBy(['address' => $address]) as $link) {
            if (!$link instanceof AccountAddress) {
                continue;
            }

            if ($link->getAccount() === $account) {
                $this->entityManager->remove($link);
                $accountLinkFound = true;
            } else {
                $otherAccountLinkFound = true;
            }
        }

        if (!$accountLinkFound) {
            return;
        }

        $hasEmailLinks = null !== $this->entityManager->getRepository(EmailAddressLink::class)->findOneBy(['address' => $address]);
        if (!$otherAccountLinkFound && !$hasEmailLinks) {
            $this->entityManager->remove($address);
        }

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

    private function isSharedWithOtherAccounts(Account $account, Address $address): bool
    {
        foreach ($this->entityManager->getRepository(AccountAddress::class)->findBy(['address' => $address]) as $link) {
            if ($link instanceof AccountAddress && $link->getAccount() !== $account) {
                return true;
            }
        }
        return array_any($this->entityManager->getRepository(EmailAddressLink::class)->findBy(['address' => $address]), fn($link) => $link instanceof EmailAddressLink && $link->getEmail()?->getAccount() !== $account);
    }

    private function linkAddress(Account $account, Address $address): void
    {
        $link = new AccountAddress();
        $link->setAccount($account);
        $link->setAddress($address);
        $this->entityManager->persist($link);
    }

    private function copyContact(Address $address): Address
    {
        return new Address()
            ->setEmail($address->getEmail())
            ->setName($address->getName())
            ->setIsContact($address->isContact())
            ->setFirstname($address->getFirstname())
            ->setLastname($address->getLastname())
            ->setCompany($address->getCompany())
            ->setBusinessPhone($address->getBusinessPhone())
            ->setMobilePhone($address->getMobilePhone())
            ->setHomePhone($address->getHomePhone());
    }
}
