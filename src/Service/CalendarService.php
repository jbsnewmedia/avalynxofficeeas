<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Licensing\LicenseState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Reads and mutates the calendar events that are exposed to EAS clients.
 */
final readonly class CalendarService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarMapper $calendarMapper,
        private LicenseState $licenseState,
    ) {
    }

    /**
     * @return list<CalendarEvent>
     */
    public function eventsForAccount(Account $account): array
    {
        /** @var list<CalendarEvent> $events */
        $events = $this->entityManager->getRepository(CalendarEvent::class)->findBy(
            ['account' => $account],
            ['startAt' => 'ASC'],
        );

        return $events;
    }

    public function eventForAccount(Account $account, UuidV7 $id): ?CalendarEvent
    {
        $event = $this->entityManager->find(CalendarEvent::class, $id);

        if (
            null === $event
            || null === $event->getAccount()
            || null === $event->getAccount()->getId()
            || null === $account->getId()
            || !$event->getAccount()->getId()->equals($account->getId())
        ) {
            return null;
        }

        return $event;
    }

    public function create(Account $account, \SyncAppointment $appointment): CalendarEvent
    {
        $this->licenseState->assertMutationAllowed('CALENDAR_WRITE');
        $event = new CalendarEvent();
        // Pre-initialize non-nullable fields: the device may omit them for
        // malformed appointments; apply overwrites them when present.
        $event->setTitle('');
        $event->setStartAt(new \DateTimeImmutable());
        $this->calendarMapper->applySyncAppointment($event, $appointment);
        $event->setAccount($account);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function update(CalendarEvent $event, \SyncAppointment $appointment): void
    {
        $this->licenseState->assertMutationAllowed('CALENDAR_WRITE');
        $this->calendarMapper->applySyncAppointment($event, $appointment);
        $this->entityManager->flush();
    }

    public function delete(CalendarEvent $event): void
    {
        $this->licenseState->assertMutationAllowed('CALENDAR_WRITE');
        $this->entityManager->remove($event);
        $this->entityManager->flush();
    }
}
