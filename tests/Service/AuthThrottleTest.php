<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Service\AuthThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class AuthThrottleTest extends TestCase
{
    private string $tmpDir;

    private AuthThrottle $throttle;

    private bool $hadForwarded = false;

    private mixed $forwardedBackup = null;

    private bool $hadRealIp = false;

    private mixed $realIpBackup = null;

    private bool $hadRemote = false;

    private mixed $remoteBackup = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/avalynxofficeeas-auth-throttle-'.uniqid('', true);
        $this->throttle = new AuthThrottle(new FilesystemAdapter('test', 0, $this->tmpDir));
        $this->hadForwarded = array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER);
        $this->forwardedBackup = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $this->hadRealIp = array_key_exists('HTTP_X_REAL_IP', $_SERVER);
        $this->realIpBackup = $_SERVER['HTTP_X_REAL_IP'] ?? null;
        $this->hadRemote = array_key_exists('REMOTE_ADDR', $_SERVER);
        $this->remoteBackup = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadForwarded) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $this->forwardedBackup;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        if ($this->hadRealIp) {
            $_SERVER['HTTP_X_REAL_IP'] = $this->realIpBackup;
        } else {
            unset($_SERVER['HTTP_X_REAL_IP']);
        }

        if ($this->hadRemote) {
            $_SERVER['REMOTE_ADDR'] = $this->remoteBackup;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        foreach ((array) glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmpDir);
    }

    public function testIsNotBlockedWithoutFailures(): void
    {
        self::assertFalse($this->throttle->isBlocked('198.51.100.7', 'user@example.com'));
    }

    public function testIpIsBlockedAfterTenFailures(): void
    {
        for ($i = 0; $i < AuthThrottle::MAX_IP_FAILURES; ++$i) {
            $this->throttle->recordFailure('198.51.100.7', 'user@example.com');
        }

        self::assertTrue($this->throttle->isBlocked('198.51.100.7', 'user@example.com'));
        self::assertTrue($this->throttle->isBlocked('198.51.100.7', 'other@example.com'));
        // ten failures are far below the per-user limit: the user keeps working from another address
        self::assertFalse($this->throttle->isBlocked('198.51.100.8', 'USER@example.com'));
        self::assertFalse($this->throttle->isBlocked('198.51.100.8', 'other@example.com'));
    }

    public function testNineFailuresDoNotBlock(): void
    {
        for ($i = 0; $i < AuthThrottle::MAX_IP_FAILURES - 1; ++$i) {
            $this->throttle->recordFailure('203.0.113.5', 'user@example.com');
        }

        self::assertFalse($this->throttle->isBlocked('203.0.113.5', 'user@example.com'));
    }

    public function testUserIsBlockedOnlyAfterFiftyFailuresFromDistinctIps(): void
    {
        self::assertGreaterThan(AuthThrottle::MAX_IP_FAILURES, AuthThrottle::MAX_USER_FAILURES);

        for ($i = 0; $i < AuthThrottle::MAX_USER_FAILURES - 1; ++$i) {
            // one failure per address: no IP counter ever reaches its limit
            $this->throttle->recordFailure(sprintf('203.0.%d.%d', intdiv($i, 250), $i % 250 + 1), 'user@example.com');
        }

        self::assertFalse($this->throttle->isBlocked('198.51.100.20', 'user@example.com'));

        $this->throttle->recordFailure('203.0.5.1', 'user@example.com');

        self::assertTrue($this->throttle->isBlocked('198.51.100.20', 'user@example.com'));
        self::assertTrue($this->throttle->isBlocked('198.51.100.20', ' USER@example.com '));
        self::assertFalse($this->throttle->isBlocked('198.51.100.20', 'other@example.com'));
    }

    public function testResetClearsFailures(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->throttle->recordFailure('203.0.113.9', 'user@example.com');
        }

        self::assertTrue($this->throttle->isBlocked('203.0.113.9', 'user@example.com'));
        $this->throttle->reset('203.0.113.9', 'user@example.com');
        self::assertFalse($this->throttle->isBlocked('203.0.113.9', 'user@example.com'));
    }

    public function testClientIpUsesEdgeRealIpAndIgnoresForwardedFor(): void
    {
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        self::assertSame('198.51.100.1', AuthThrottle::clientIp());

        $_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';
        self::assertSame('10.0.0.2', AuthThrottle::clientIp());

        $_SERVER['REMOTE_ADDR'] = 'invalid';
        self::assertSame('unknown', AuthThrottle::clientIp());
    }
}
