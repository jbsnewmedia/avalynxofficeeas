<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Type\UuidV7Type;
use AvalynxOfficeEas\Service\ProvisioningPolicyService;
use AvalynxOfficeEas\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The provisioning policy merges the all-off baseline with the admin's
 * stored deviations from the shared app_setting store.
 */
#[Group('integration')]
final class ProvisioningPolicyServiceTest extends TestCase
{
    use TestEnvironment;

    protected function setUp(): void
    {
        self::bootEnvironment();
        ProvisioningPolicyService::resetCache();
    }

    protected function tearDown(): void
    {
        ProvisioningPolicyService::resetCache();
        self::tearDownEnvironment();
    }

    public function testBaselineHasAllRestrictionsOff(): void
    {
        $policy = ProvisioningPolicyService::policy(self::em());

        self::assertSame(0, $policy['devpwenabled']);
        self::assertSame(0, $policy['alphanumpwreq']);
        self::assertSame('', $policy['mindevpwlenngth']);
        self::assertSame('', $policy['maxinacttimedevlock']);
        self::assertSame('', $policy['maxdevpwfailedattempts']);
        self::assertSame('', $policy['mindevcomplexchars']);
        self::assertSame(0, $policy['devpwexpiration']);
        self::assertSame(0, $policy['reqdevenc']);
        self::assertSame(0, $policy['devencenabled']);

        // Allowed-by-default device features stay untouched.
        self::assertSame(1, $policy['allowcam']);
        self::assertSame(1, $policy['allowstoragecard']);
        self::assertSame(1, $policy['allowwifi']);
        self::assertSame(1, $policy['allowtextmessaging']);
        self::assertSame(1, $policy['allowpopimapemail']);
        self::assertSame(1, $policy['allowbrowser']);
        self::assertSame(1, $policy['allowinternetsharing']);
        self::assertSame(2, $policy['allowbluetooth']);
    }

    public function testStoredDeviationsOverrideBaseline(): void
    {
        $connection = self::em()->getConnection();
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.devpwenabled', 'setting_value' => '1'], ['id' => UuidV7Type::NAME]);
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.allowcam', 'setting_value' => '0'], ['id' => UuidV7Type::NAME]);
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.mindevpwlenngth', 'setting_value' => '8'], ['id' => UuidV7Type::NAME]);
        ProvisioningPolicyService::resetCache();

        $policy = ProvisioningPolicyService::policy(self::em());

        self::assertSame(1, $policy['devpwenabled']);
        self::assertSame(0, $policy['allowcam']);
        self::assertSame(8, $policy['mindevpwlenngth']);

        // Keys without a stored deviation stay off.
        self::assertSame('', $policy['maxinacttimedevlock']);
        self::assertSame(0, $policy['alphanumpwreq']);
    }

    public function testUnknownKeysAndEmptyValuesAreIgnored(): void
    {
        $connection = self::em()->getConnection();
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.notarealpolicy', 'setting_value' => '5'], ['id' => UuidV7Type::NAME]);
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.allowcam', 'setting_value' => ''], ['id' => UuidV7Type::NAME]);
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'sync.interval_seconds', 'setting_value' => '60'], ['id' => UuidV7Type::NAME]);
        ProvisioningPolicyService::resetCache();

        $policy = ProvisioningPolicyService::policy(self::em());

        self::assertSame(1, $policy['allowcam']);
        self::assertArrayNotHasKey('notarealpolicy', $policy);
        self::assertSame(1, $policy['allowcam']);
    }

    public function testCacheServesRepeatedReadsWithoutReset(): void
    {
        $connection = self::em()->getConnection();
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.allowcam', 'setting_value' => '0'], ['id' => UuidV7Type::NAME]);

        ProvisioningPolicyService::resetCache();
        $first = ProvisioningPolicyService::policy(self::em());
        self::assertSame(0, $first['allowcam']);

        // A second stored change must NOT appear until the TTL cache is
        // reset: the policy read happens per request, not per sync loop.
        $connection->insert('app_setting', ['id' => Uuid::v7(), 'setting_key' => 'eas.policy.allowwifi', 'setting_value' => '0'], ['id' => UuidV7Type::NAME]);
        $cached = ProvisioningPolicyService::policy(self::em());
        self::assertSame(1, $cached['allowwifi']);

        ProvisioningPolicyService::resetCache();
        $fresh = ProvisioningPolicyService::policy(self::em());
        self::assertSame(0, $fresh['allowwifi']);
    }
}
