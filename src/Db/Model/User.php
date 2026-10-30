<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
class User
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;
    /**
     * Last successful authentication (web login or device sync). Part of the
     * Active Seat definition (APL 1.0): only accounts that authenticated
     * within the last 30 calendar days consume a license seat. Written by
     * the core on login and by this plugin on every successful Logon().
     */
    #[ORM\Column(name: 'last_auth_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAuthAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower($email);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getLastAuthAt(): ?\DateTimeImmutable
    {
        return $this->lastAuthAt;
    }

    public function setLastAuthAt(?\DateTimeImmutable $lastAuthAt): self
    {
        $this->lastAuthAt = $lastAuthAt;

        return $this;
    }
}
