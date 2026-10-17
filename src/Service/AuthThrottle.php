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
 * client IP in a time window and blocks further attempts while the limit is
 * exceeded. Successful authentication clears the counter.
 *
 * Ported from the mailer project (App\Eas\Security\EasAuthThrottle) to the
 * Z-Push backend context.
 */
final readonly class AuthThrottle
{
    private const int MAX_FAILURES = 10;
    private const int WINDOW_SECONDS = 300;

    public function __construct(private readonly AdapterInterface $cache)
    {
    }

    public function isBlocked(string $clientIp): bool
    {
        return $this->failures($clientIp) >= self::MAX_FAILURES;
    }

    public function recordFailure(string $clientIp): void
    {
        $key = $this->key($clientIp);
        $item = $this->cache->getItem($key);
        $current = $item->get();
        $item->set(is_int($current) ? $current + 1 : 1);
        $item->expiresAfter(self::WINDOW_SECONDS);
        $this->cache->save($item);
    }

    public function reset(string $clientIp): void
    {
        $this->cache->deleteItem($this->key($clientIp));
    }

    /**
     * Resolves the client IP for the throttle key. Devices reach the EAS
     * container through the stack nginx and an optional upstream TLS proxy,
     * both of which append to X-Forwarded-For - the first entry is the
     * original client.
     */
    public static function clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if (is_string($forwarded) && '' !== trim($forwarded)) {
            $first = trim(explode(',', $forwarded)[0]);

            if ('' !== $first) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) && '' !== $remote ? $remote : 'unknown';
    }

    private function key(string $clientIp): string
    {
        return 'eas_auth_fail_'.md5($clientIp);
    }

    private function failures(string $clientIp): int
    {
        $item = $this->cache->getItem($this->key($clientIp));
        $value = $item->get();

        return is_int($value) ? $value : 0;
    }
}
