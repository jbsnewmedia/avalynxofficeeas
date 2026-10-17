<?php

declare(strict_types=1);

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

    private bool $hadRemote = false;

    private mixed $remoteBackup = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/avalynxofficeeas-auth-throttle-'.uniqid('', true);
        $this->throttle = new AuthThrottle(new FilesystemAdapter('test', 0, $this->tmpDir));
        $this->hadForwarded = array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER);
        $this->forwardedBackup = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
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
        self::assertFalse($this->throttle->isBlocked('198.51.100.7'));
    }

    public function testBlocksAfterTenFailures(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->throttle->recordFailure('198.51.100.7');
        }

        self::assertTrue($this->throttle->isBlocked('198.51.100.7'));
        self::assertFalse($this->throttle->isBlocked('198.51.100.8'));
    }

    public function testNineFailuresDoNotBlock(): void
    {
        for ($i = 0; $i < 9; ++$i) {
            $this->throttle->recordFailure('203.0.113.5');
        }

        self::assertFalse($this->throttle->isBlocked('203.0.113.5'));
    }

    public function testResetClearsFailures(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->throttle->recordFailure('203.0.113.9');
        }

        self::assertTrue($this->throttle->isBlocked('203.0.113.9'));
        $this->throttle->reset('203.0.113.9');
        self::assertFalse($this->throttle->isBlocked('203.0.113.9'));
    }

    public function testClientIpPrefersFirstForwardedForEntry(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        self::assertSame('198.51.100.1', AuthThrottle::clientIp());

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '  ';
        self::assertSame('10.0.0.2', AuthThrottle::clientIp());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        self::assertSame('10.0.0.2', AuthThrottle::clientIp());

        unset($_SERVER['REMOTE_ADDR']);
        self::assertSame('unknown', AuthThrottle::clientIp());
    }
}
