<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only mirror of the AvalynxOffice licensing state. The core owns this
 * table; the plugin only reads it to mirror the read-only policy for
 * device-initiated writes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'license_state')]
class LicenseState
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;
    #[ORM\Column(name: 'last_known_status', type: 'string', length: 20, nullable: true)]
    private ?string $lastKnownStatus = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getLastKnownStatus(): ?string
    {
        return $this->lastKnownStatus;
    }

    public function setLastKnownStatus(?string $lastKnownStatus): self
    {
        $this->lastKnownStatus = $lastKnownStatus;

        return $this;
    }
}
