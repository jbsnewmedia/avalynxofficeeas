<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Dto;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\User;
use Symfony\Component\Uid\UuidV7;

/**
 * Resolved EAS login context: the authenticated application user and every
 * account assigned to them (mail, contacts and calendar are synchronized
 * for all of them, exactly like the AvalynxOffice web login).
 */
final readonly class EasUserContext
{
    /** @var array<string, Account> accounts keyed by RFC 4122 id */
    private array $accountsById;

    /**
     * @param list<Account> $accounts sorted by account e-mail address
     */
    public function __construct(
        private User $user,
        private array $accounts,
    ) {
        $byId = [];
        foreach ($this->accounts as $account) {
            $id = $account->getId();

            if (null !== $id) {
                $byId[$id->toRfc4122()] = $account;
            }
        }

        $this->accountsById = $byId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return list<Account>
     */
    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function hasAccount(UuidV7 $accountId): bool
    {
        return isset($this->accountsById[$accountId->toRfc4122()]);
    }

    public function getAccount(UuidV7 $accountId): ?Account
    {
        return $this->accountsById[$accountId->toRfc4122()] ?? null;
    }

    /**
     * The first account (sorted by e-mail address); used for operations that
     * carry no account scope (folder creation at the root level, the waste
     * basket, new messages without a source folder).
     */
    public function getPrimaryAccount(): ?Account
    {
        return $this->accounts[0] ?? null;
    }
}
