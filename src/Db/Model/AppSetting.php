<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Read-only mirror of the AvalynxOffice app_setting key/value store. The
 * core owns this table; the plugin only reads the "eas.policy.*" keys to
 * build the ActiveSync provisioning policy (ProvisioningPolicyService).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(name: 'setting_key', type: 'string', length: 190, unique: true)]
    private string $settingKey = '';
    #[ORM\Column(name: 'setting_value', type: 'text', nullable: true)]
    private ?string $settingValue = null;

    public function __construct()
    {
        $this->id = \Symfony\Component\Uid\Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }
}
