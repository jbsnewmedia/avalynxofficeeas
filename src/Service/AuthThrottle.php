<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Database;
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
     * Resolves the client IP for the brute-force buckets.
     *
     * The X-Real-IP header is only honoured when the immediate peer
     * (REMOTE_ADDR) is a configured trusted proxy (EAS_TRUSTED_PROXIES,
     * comma separated IPs/CIDR networks - the stack's nginx edge). When the
     * endpoint is reached directly, or the peer is not trusted, the header is
     * attacker controlled and REMOTE_ADDR is used instead. The nginx edge
     * overwrites the header with the real client address, so per-client
     * throttling keeps working behind the proxy.
     */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) && false !== filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';

        if ('' !== $remote && self::isTrustedProxyAddress($remote)) {
            $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if (is_string($realIp) && false !== filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }
        }

        return '' !== $remote ? $remote : 'unknown';
    }

    /**
     * Whether the given address belongs to the configured trusted proxies.
     *
     * Entries may be single IPs (v4/v6) or IPv4 CIDR networks. An empty
     * configuration trusts nobody - callers then never read a client
     * controlled X-Real-IP header.
     */
    public static function isTrustedProxyAddress(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $configured = Database::env('EAS_TRUSTED_PROXIES', '') ?? '';

        foreach (array_filter(array_map(trim(...), explode(',', (string) $configured))) as $entry) {
            if ($entry === $ip) {
                return true;
            }

            if (str_contains($entry, '/') && str_contains($ip, '.') && self::ipv4InCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
        $prefix = (int) $prefix;

        if (false === filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || $prefix < 0 || $prefix > 32
            || false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            return false;
        }

        $mask = 0 === $prefix ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
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
