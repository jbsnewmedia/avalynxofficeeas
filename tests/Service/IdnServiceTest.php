<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Service\IdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The central IDN conversion: device input normalization to punycode and
 * display decoding to Unicode. Values without a convertible domain pass
 * through unchanged.
 */
final class IdnServiceTest extends TestCase
{
    public static function provideAsciiCases(): \Generator
    {
        yield 'unicode email' => ['user@bästoff.de', 'user@xn--bstoff-bua.de'];
        yield 'unicode bare domain' => ['bästoff.de', 'xn--bstoff-bua.de'];
        yield 'mixed case unicode email' => ['User@Bästoff.DE', 'User@xn--bstoff-bua.de'];
        yield 'punycode email is idempotent' => ['user@xn--bstoff-bua.de', 'user@xn--bstoff-bua.de'];
        yield 'ascii email passes through' => ['user@example.com', 'user@example.com'];
        yield 'subdomain with unicode label' => ['mail.bästoff.de', 'mail.xn--bstoff-bua.de'];
        yield 'no domain is returned unchanged' => ['plain-text', 'plain-text'];
        yield 'empty string stays empty' => ['', ''];
    }

    #[DataProvider('provideAsciiCases')]
    public function testToAscii(string $input, string $expected): void
    {
        self::assertSame($expected, new IdnService()->toAscii($input));
    }

    public static function provideUnicodeCases(): \Generator
    {
        yield 'punycode email' => ['admin@xn--bstoff-bua.de', 'admin@bästoff.de'];
        yield 'punycode bare domain' => ['xn--bstoff-bua.de', 'bästoff.de'];
        yield 'ascii email passes through' => ['admin@example.com', 'admin@example.com'];
        yield 'subdomain with punycode label' => ['mail.xn--bstoff-bua.de', 'mail.bästoff.de'];
        yield 'no domain is returned unchanged' => ['plain-text', 'plain-text'];
        yield 'empty string stays empty' => ['', ''];
    }

    #[DataProvider('provideUnicodeCases')]
    public function testToUnicode(string $input, string $expected): void
    {
        self::assertSame($expected, new IdnService()->toUnicode($input));
    }

    public function testAsciiAndUnicodeRoundTrip(): void
    {
        $service = new IdnService();

        self::assertSame('bästoff.de', $service->toUnicode($service->toAscii('bästoff.de')));
        self::assertSame('user@bästoff.de', $service->toUnicode($service->toAscii('user@bästoff.de')));
    }
}
