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

/**
 * A single modified or deleted occurrence of a recurring calendar event
 * (EAS SyncAppointmentException): exception_start_at pins the original
 * occurrence, the remaining columns override the series values.
 */
#[ORM\Entity]
#[ORM\Table(name: 'calendar_event_exception')]
#[ORM\Index(name: 'idx_calendar_event_exception_event', columns: ['event_id'])]
class CalendarEventException
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: CalendarEvent::class, inversedBy: 'exceptions')]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CalendarEvent $event = null;
    #[ORM\Column(name: 'exception_start_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $exceptionStartAt;
    #[ORM\Column(name: 'is_deleted', type: 'boolean', options: ['default' => false])]
    private bool $isDeleted = false;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $title = null;
    #[ORM\Column(name: 'start_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startAt = null;
    #[ORM\Column(name: 'end_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endAt = null;
    #[ORM\Column(name: 'is_all_day', type: 'boolean', options: ['default' => false])]
    private bool $isAllDay = false;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $location = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;
    #[ORM\Column(name: 'busy_status', type: 'smallint', nullable: true)]
    private ?int $busyStatus = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $reminder = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEvent(): ?CalendarEvent
    {
        return $this->event;
    }

    public function setEvent(?CalendarEvent $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getExceptionStartAt(): \DateTimeImmutable
    {
        return $this->exceptionStartAt;
    }

    public function setExceptionStartAt(\DateTimeImmutable $exceptionStartAt): self
    {
        $this->exceptionStartAt = $exceptionStartAt;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(bool $isDeleted): self
    {
        $this->isDeleted = $isDeleted;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeImmutable $startAt): self
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->isAllDay;
    }

    public function setIsAllDay(bool $isAllDay): self
    {
        $this->isAllDay = $isAllDay;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getBusyStatus(): ?int
    {
        return $this->busyStatus;
    }

    public function setBusyStatus(?int $busyStatus): self
    {
        $this->busyStatus = $busyStatus;

        return $this;
    }

    public function getReminder(): ?int
    {
        return $this->reminder;
    }

    public function setReminder(?int $reminder): self
    {
        $this->reminder = $reminder;

        return $this;
    }
}
