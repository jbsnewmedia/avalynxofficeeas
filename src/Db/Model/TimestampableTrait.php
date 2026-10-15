<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Timestampable behaviour for the plugin's database models. Mirrors the
 * AvalynxOffice schema columns (created_at NOT NULL, updated_at nullable).
 */
trait TimestampableTrait
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function touchOnCreate(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touchOnUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
