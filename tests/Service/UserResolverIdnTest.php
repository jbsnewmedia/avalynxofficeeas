<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Model\User;
use AvalynxOfficeEas\Db\Model\UserAppPassword;
use AvalynxOfficeEas\Service\ServiceHolder;
use AvalynxOfficeEas\Service\UserResolver;
use AvalynxOfficeEas\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The EAS login matches the identifier the device typed against the stored
 * punycode email: "User@Bästoff.DE" finds the user stored as
 * "user@xn--bstoff-bua.de" — independently of whether the device or its
 * input method punycode-normalizes on its own.
 */
final class UserResolverIdnTest extends TestCase
{
    use TestEnvironment;

    private const string IDN_STORED_EMAIL = 'user@xn--bstoff-bua.de';

    private const string APP_PASSWORD = 'app-secret';

    protected function setUp(): void
    {
        self::bootEnvironment();
        $this->seedIdnUser();
    }

    protected function tearDown(): void
    {
        self::tearDownEnvironment();
    }

    public function testResolvesUnicodeLogin(): void
    {
        $context = $this->resolver()->resolve('User@Bästoff.DE', self::APP_PASSWORD);

        self::assertNotNull($context);
        self::assertSame(self::IDN_STORED_EMAIL, $context->getUser()->getEmail());
    }

    public function testResolvesPunycodeLogin(): void
    {
        $context = $this->resolver()->resolve(self::IDN_STORED_EMAIL, self::APP_PASSWORD);

        self::assertNotNull($context);
    }

    public function testRejectsUnknownUnicodeLogin(): void
    {
        self::assertNull($this->resolver()->resolve('nobody@bästoff.de', self::APP_PASSWORD));
    }

    public function testRejectsIdnUserWithWrongPassword(): void
    {
        self::assertNull($this->resolver()->resolve('user@bästoff.de', 'wrong'));
    }

    private function resolver(): UserResolver
    {
        return ServiceHolder::instance()->getUserResolver();
    }

    private function seedIdnUser(): void
    {
        $em = self::em();

        $user = new User();
        $user->setEmail(self::IDN_STORED_EMAIL);
        $em->persist($user);

        $appPassword = new UserAppPassword();
        $appPassword->setUser($user);
        $appPassword->setLabel('IDN device');
        // The core stores the digest of the normalized password form.
        $appPassword->setPasswordHash(password_hash(UserResolver::normalizePassword(self::APP_PASSWORD), PASSWORD_BCRYPT));
        $em->persist($appPassword);
        $em->flush();
        $em->clear();
    }
}
