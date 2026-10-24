<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\User;
use AvalynxOfficeEas\Dto\EasUserContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves an EAS login (application user e-mail address) to the user and
 * every account assigned to them, and verifies the credentials against the
 * activated application user — the same login as the AvalynxOffice web app.
 *
 * Mailbox or alias addresses are intentionally not accepted as usernames:
 * a device session always belongs to a user, never to a single account.
 */
final readonly class UserResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(string $username, string $password): ?EasUserContext
    {
        $email = mb_strtolower(trim($username));

        if ('' === $email) {
            return null;
        }

        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('LOWER(u.email) = :email')
            ->andWhere('u.active = true')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User || null === $user);

        if (null === $user || !$this->isPasswordValid($user, $password)) {
            return null;
        }

        return new EasUserContext($user, $this->accountsForUser($user));
    }

    /**
     * Passwords are verified with password_verify, which handles the hash
     * formats produced by the core's "auto" password hasher (bcrypt and
     * argon2i/argon2id).
     */
    private function isPasswordValid(User $user, string $password): bool
    {
        return password_verify($password, $user->getPassword());
    }

    /**
     * Every account assigned to the user, sorted by e-mail address — the
     * same set the web UI shows after the login.
     *
     * @return list<Account>
     */
    private function accountsForUser(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT a.id FROM user_account ul JOIN account a ON a.id = ul.account_id WHERE ul.user_id = :userId ORDER BY a.email ASC',
            ['userId' => $user->getId()?->toRfc4122()],
        );

        $accounts = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (is_string($id) && '' !== $id) {
                $account = $this->entityManager->find(Account::class, $id);

                if (null !== $account) {
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }
}
