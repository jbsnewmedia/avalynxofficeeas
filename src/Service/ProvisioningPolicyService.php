<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\AppSetting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the ActiveSync provisioning policy the EAS announces to devices.
 *
 * The AvalynxOffice core stores the admin's device policy decisions in its
 * app_setting key/value store (keys "eas.policy.<zpushkey>"). This service
 * merges those deviations over the ALL-OFF baseline and overrides Z-Push's
 * [default] policy through reflection — Z-Push only loads policies.ini
 * once per worker, while the admin setting must apply on every request.
 *
 * Everything defaults to off: a device without any stored deviation is
 * provisioned without restrictions.
 */
final class ProvisioningPolicyService
{
    private const string KEY_PREFIX = 'eas.policy.';
    private const int CACHE_TTL_SECONDS = 30;
    private const string DEFAULT_POLICY_NAME = 'default';

    /** @var array<string, int|string>|null per-process TTL cache of the merged policy */
    private static ?array $cache = null;

    private static int $cacheAt = 0;

    /**
     * The complete [default] policy with every restriction off. Keys and
     * semantics mirror the shipped Z-Push policies.ini; empty strings mean
     * "not set" (the device applies no rule).
     *
     * @return array<string, int|string>
     */
    public static function baseline(): array
    {
        return [
            'devpwenabled' => 0,
            'alphanumpwreq' => 0,
            'devencenabled' => 0,
            'docbrowseenabled' => '',
            'attenabled' => 1,
            'mindevpwlenngth' => '',
            'maxinacttimedevlock' => '',
            'maxdevpwfailedattempts' => '',
            'maxattsize' => -1,
            'allowsimpledevpw' => 1,
            'devpwexpiration' => 0,
            'devpwhistory' => 0,
            'allowstoragecard' => 1,
            'allowcam' => 1,
            'reqdevenc' => 0,
            'allowunsignedapps' => 1,
            'mindevcomplexchars' => '',
            'allowwifi' => 1,
            'allowtextmessaging' => 1,
            'allowpopimapemail' => 1,
            'allowbluetooth' => 2,
            'allowirda' => 1,
            'reqmansyncroam' => 0,
            'maxcalagefilter' => 0,
            'allowhtmlemail' => 1,
            'maxemailagefilter' => 0,
            'maxemailbodytruncsize' => -1,
            'maxemailhtmlbodytruncsize' => -1,
            'reqsignedsmimemessages' => 0,
            'reqencsmimemessages' => 0,
            'reqsignedsmimealgorithm' => 0,
            'reqencsmimealgorithm' => 0,
            'allowsmimeencalgneg' => 2,
            'allowsmimesoftcerts' => 1,
            'allowbrowser' => 1,
            'allowconsumeremail' => 1,
            'allowinternetsharing' => 1,
        ];
    }

    /**
     * Overrides Z-Push's [default] provisioning policy with the merged
     * policy from the shared database. No-op when provisioning is
     * disabled (Z-Push never loaded a policies array in that case).
     */
    public static function apply(EntityManagerInterface $entityManager): void
    {
        if (!class_exists(\ZPush::class)) {
            return;
        }

        $policies = new \ReflectionProperty(\ZPush::class, 'policies')->getValue();

        if (!\is_array($policies)) {
            return;
        }

        $policies[self::DEFAULT_POLICY_NAME] = self::policy($entityManager);

        new \ReflectionProperty(\ZPush::class, 'policies')->setValue(null, $policies);
    }

    /**
     * The merged policy: all-off baseline plus the admin's stored
     * deviations from the shared app_setting store.
     *
     * @return array<string, int|string>
     */
    public static function policy(EntityManagerInterface $entityManager): array
    {
        $now = time();

        if (null !== self::$cache && $now - self::$cacheAt < self::CACHE_TTL_SECONDS) {
            return self::$cache;
        }

        $policy = self::baseline();

        /** @var list<array{policy_key: string, policy_value: string|null}> $rows */
        $rows = $entityManager->createQuery(
            'SELECT s.settingKey AS policy_key, s.settingValue AS policy_value FROM '.AppSetting::class.' s WHERE s.settingKey LIKE :prefix',
        )->setParameter('prefix', self::KEY_PREFIX.'%')->getScalarResult();

        foreach ($rows as $row) {
            $policyKey = substr($row['policy_key'], strlen(self::KEY_PREFIX));
            $value = (string) ($row['policy_value'] ?? '');

            if (!array_key_exists($policyKey, $policy) || '' === $value) {
                continue;
            }

            $policy[$policyKey] = is_numeric($value) ? (int) $value : $value;
        }

        self::$cache = $policy;
        self::$cacheAt = $now;

        return $policy;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cacheAt = 0;
    }
}
