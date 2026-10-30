<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Brute-force guard for the EAS endpoint: counts failed authentications per
 * client IP and normalized username, blocking either key while its limit is
 * exceeded. Successful authentication clears both counters.
 *
 * The per-user limit is deliberately higher than the per-IP limit: a
 * stricter per-user lockout would let anyone lock out a known username
 * (lockout DoS) with a handful of requests from changing addresses.
 *
 * Ported from the mailer project (App\Eas\Security\EasAuthThrottle) to the
 * Z-Push backend context.
 */
final readonly class AuthThrottle
{
    public const int MAX_IP_FAILURES = 10;
    public const int MAX_USER_FAILURES = 50;
    public const int WINDOW_SECONDS = 300;

    public function __construct(private readonly AdapterInterface $cache)
    {
    }

    public function isBlocked(string $clientIp, string $username): bool
    {
        return $this->failures('ip', $clientIp) >= self::MAX_IP_FAILURES
            || $this->failures('user', $username) >= self::MAX_USER_FAILURES;
    }

    public function recordFailure(string $clientIp, string $username): void
    {
        $this->increment('ip', $clientIp);
        $this->increment('user', $username);
    }

    public function reset(string $clientIp, string $username): void
    {
        $this->cache->deleteItem($this->key('ip', $clientIp));
        $this->cache->deleteItem($this->key('user', $username));
    }

    /**
     * Resolves the client IP from the edge proxy's overwritten X-Real-IP
     * header; client-controlled X-Forwarded-For is deliberately ignored.
     */
    public static function clientIp(): string
    {
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (is_string($realIp) && false !== filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) && false !== filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
    }

    private function key(string $scope, string $identifier): string
    {
        $normalized = 'user' === $scope ? mb_strtolower(trim($identifier)) : trim($identifier);

        return 'eas_auth_fail_'.$scope.'_'.hash('sha256', $normalized);
    }

    private function failures(string $scope, string $identifier): int
    {
        $item = $this->cache->getItem($this->key($scope, $identifier));
        $value = $item->get();

        return is_int($value) ? $value : 0;
    }

    private function increment(string $scope, string $identifier): void
    {
        $item = $this->cache->getItem($this->key($scope, $identifier));
        $current = $item->get();
        $item->set(is_int($current) ? $current + 1 : 1);
        $item->expiresAfter(self::WINDOW_SECONDS);
        $this->cache->save($item);
    }
}
