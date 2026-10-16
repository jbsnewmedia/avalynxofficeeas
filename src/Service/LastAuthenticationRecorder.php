<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records successful device authentications as seat activity.
 *
 * AvalynxOffice (APL 1.0) counts an account as an "Active Seat" only while
 * it performed at least one successful authentication (web login or device
 * sync) within the last 30 calendar days. This recorder refreshes the
 * shared app_user.last_auth_at column on every successful Logon() so that
 * device sync keeps the seat active; the seat accounting itself stays in
 * the core.
 */
final readonly class LastAuthenticationRecorder
{
    /**
     * Devices authenticate on every sync request, so the timestamp is
     * refreshed at most once per interval.
     */
    private const int REFRESH_SECONDS = 3600;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(User $user): void
    {
        $id = $user->getId();

        if (null === $id) {
            return;
        }

        $now = new \DateTimeImmutable('now');

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE app_user SET last_auth_at = :at'
            .' WHERE id = :id AND (last_auth_at IS NULL OR last_auth_at < :threshold)',
            [
                'at' => $now->format('Y-m-d H:i:s'),
                'id' => $id->toRfc4122(),
                'threshold' => $now->modify(sprintf('-%d seconds', self::REFRESH_SECONDS))->format('Y-m-d H:i:s'),
            ],
        );
    }
}
