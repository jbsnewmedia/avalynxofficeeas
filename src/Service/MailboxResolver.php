<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Db\Model\User;
use AvalynxOfficeEas\Dto\EasMailboxContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves an EAS login (mailbox email address) to the synchronized mailbox
 * and verifies credentials against the activated application users owning
 * the mailbox.
 */
final readonly class MailboxResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(string $username, string $password): ?EasMailboxContext
    {
        $email = mb_strtolower(trim($username));

        if ('' === $email) {
            return null;
        }

        $mailbox = $this->findMailbox($email);

        if (null !== $mailbox) {
            foreach ($this->usersForMailbox($mailbox) as $user) {
                if ($user->isActive() && $this->isPasswordValid($user, $password)) {
                    return new EasMailboxContext($mailbox, $user);
                }
            }

            return null;
        }

        // Clients (iOS/Android) use the account email as username. If no
        // mailbox matches, the mailbox owned by that application user is used.
        return $this->resolveByUser($email, $password);
    }

    private function resolveByUser(string $email, string $password): ?EasMailboxContext
    {
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

        $mailboxId = $this->entityManager->getConnection()->fetchOne(
            'SELECT ul.mailbox_id FROM user_mailbox ul JOIN mailbox m ON m.id = ul.mailbox_id WHERE ul.user_id = :userId ORDER BY m.email ASC LIMIT 1',
            ['userId' => $user->getId()?->toRfc4122()],
        );

        if (!is_string($mailboxId) || '' === $mailboxId) {
            return null;
        }

        $mailbox = $this->entityManager->find(Mailbox::class, $mailboxId);

        if (null === $mailbox) {
            return null;
        }

        return new EasMailboxContext($mailbox, $user);
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
     * @return list<User>
     */
    private function usersForMailbox(Mailbox $mailbox): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT u.id FROM app_user u JOIN user_mailbox ul ON ul.user_id = u.id WHERE ul.mailbox_id = :mailboxId',
            ['mailboxId' => $mailbox->getId()?->toRfc4122()],
        );

        $users = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (is_string($id) && '' !== $id) {
                $user = $this->entityManager->find(User::class, $id);

                if (null !== $user) {
                    $users[] = $user;
                }
            }
        }

        return $users;
    }

    private function findMailbox(string $email): ?Mailbox
    {
        $connection = $this->entityManager->getConnection();

        $mailboxId = $connection->fetchOne(
            'SELECT id FROM mailbox WHERE email = :email ORDER BY email ASC LIMIT 1',
            ['email' => $email],
        );

        if (!is_string($mailboxId) || '' === $mailboxId) {
            // Account aliases may also live on the mailbox connectors.
            try {
                $mailboxId = $connection->fetchOne(
                    'SELECT mailbox_id FROM mailbox_connector WHERE email = :email LIMIT 1',
                    ['email' => $email],
                );
            } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
                return null;
            }
        }

        if (!is_string($mailboxId) || '' === $mailboxId) {
            return null;
        }

        return $this->entityManager->find(Mailbox::class, $mailboxId);
    }
}
