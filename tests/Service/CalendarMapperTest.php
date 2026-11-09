<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Db\Model\CalendarEventException;
use AvalynxOfficeEas\Service\CalendarMapper;
use PHPUnit\Framework\TestCase;

/**
 * Full-field round trips between CalendarEvent models and SyncAppointment
 * objects, including attendees, organizer, online meeting links and
 * recurrence exceptions.
 */
final class CalendarMapperTest extends TestCase
{
    protected function setUp(): void
    {
        // The mapper branches on the negotiated AS version; emulate a
        // modern device header since there is no request processor.
        self::setProtocolVersion('14.1');
    }

    protected function tearDown(): void
    {
        self::setProtocolVersion(null);
    }

    public function testBuildMapsEveryStoredField(): void
    {
        $appointment = new CalendarMapper()->buildSyncAppointment(self::filledEvent());

        self::assertSame('Team meeting', $appointment->subject);
        self::assertSame(1, $appointment->alldayevent);
        self::assertSame(new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp(), $appointment->starttime);
        self::assertSame(new \DateTimeImmutable('2026-11-10 10:00')->getTimestamp(), $appointment->endtime);
        self::assertSame('Meeting room 1', $appointment->location);
        self::assertSame('event-uid-1', $appointment->uid);
        self::assertSame(2, $appointment->busystatus);
        self::assertSame(1, $appointment->sensitivity);
        self::assertSame(15, $appointment->reminder);
        self::assertSame('Alice Organizer', $appointment->organizername);
        self::assertSame('alice@example.com', $appointment->organizeremail);
        self::assertSame(1, $appointment->meetingstatus);
        self::assertSame(2, $appointment->responsetype);
        self::assertSame(1, $appointment->responserequested);
        self::assertSame(1, $appointment->disallownewtimeprop);
        self::assertSame('https://conf.example.com/meet', $appointment->onlineMeetingConfLink);
        self::assertSame('https://ext.example.com/meet', $appointment->onlineMeetingExternalLink);
        self::assertSame(['Work', 'Team'], $appointment->categories);
        self::assertIsString($appointment->timezone);
        self::assertNotFalse(base64_decode($appointment->timezone, true));

        self::assertInstanceOf(\SyncBaseBody::class, $appointment->asbody);
        self::assertIsResource($appointment->asbody->data);
        self::assertSame('Weekly sync', (string) stream_get_contents($appointment->asbody->data));

        $recurrence = $appointment->recurrence;
        self::assertInstanceOf(\SyncRecurrence::class, $recurrence);
        self::assertSame(1, $recurrence->type);
        self::assertSame(2, $recurrence->interval);
        self::assertSame(new \DateTimeImmutable('2026-12-31 00:00')->getTimestamp(), $recurrence->until);
        self::assertSame(10, $recurrence->occurrences);
        self::assertSame(62, $recurrence->dayofweek);
        self::assertSame(0, $recurrence->calendartype);
        self::assertSame(1, $recurrence->firstdayofweek);

        $attendees = $appointment->attendees;
        self::assertIsArray($attendees);
        self::assertCount(2, $attendees);
        self::assertInstanceOf(\SyncAttendee::class, $attendees[0]);
        self::assertInstanceOf(\SyncAttendee::class, $attendees[1]);
        self::assertSame('Bob Guest', $attendees[0]->name);
        self::assertSame('bob@example.com', $attendees[0]->email);
        self::assertSame(1, $attendees[0]->attendeetype);
        self::assertSame(3, $attendees[0]->attendeestatus);
        self::assertSame('Carol Optional', $attendees[1]->name);
        self::assertSame('carol@example.com', $attendees[1]->email);

        $exceptions = $appointment->exceptions;
        self::assertIsArray($exceptions);
        self::assertCount(2, $exceptions);

        $deleted = $exceptions[0];
        self::assertInstanceOf(\SyncAppointmentException::class, $deleted);
        self::assertSame(1, $deleted->deleted);
        self::assertSame(new \DateTimeImmutable('2026-11-24 09:00')->getTimestamp(), $deleted->exceptionstarttime);

        $moved = $exceptions[1];
        self::assertInstanceOf(\SyncAppointmentException::class, $moved);
        self::assertSame(0, $moved->deleted);
        self::assertSame(new \DateTimeImmutable('2026-12-08 09:00')->getTimestamp(), $moved->exceptionstarttime);
        self::assertSame('Moved session', $moved->subject);
        self::assertSame(new \DateTimeImmutable('2026-12-08 14:00')->getTimestamp(), $moved->starttime);
        self::assertSame(new \DateTimeImmutable('2026-12-08 15:00')->getTimestamp(), $moved->endtime);
        self::assertSame('Room 2', $moved->location);
        self::assertSame(3, $moved->busystatus);
        self::assertSame(30, $moved->reminder);
        self::assertInstanceOf(\SyncBaseBody::class, $moved->asbody);
        self::assertIsResource($moved->asbody->data);
        self::assertSame('Moved note', (string) stream_get_contents($moved->asbody->data));
    }

    public function testStatAnnouncesEvent(): void
    {
        $event = new CalendarEvent();
        $event->setTitle('Series');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $event->touch();

        $stat = new CalendarMapper()->stat($event);
        $updatedAt = $event->getUpdatedAt();
        self::assertNotNull($updatedAt);

        self::assertSame($event->getId()?->toRfc4122(), $stat['id']);
        self::assertSame((string) $updatedAt->getTimestamp(), $stat['mod']);
        self::assertSame(0, $stat['flags']);
        self::assertSame(0, $stat['star']);
    }

    public function testBuildMapsDtstampAndFullRecurrencePattern(): void
    {
        $appointment = new CalendarMapper()->buildSyncAppointment(self::filledEvent());

        self::assertSame(new \DateTimeImmutable('2026-11-09 10:00')->getTimestamp(), $appointment->dtstamp);

        $recurrence = $appointment->recurrence;
        self::assertInstanceOf(\SyncRecurrence::class, $recurrence);
        self::assertSame(15, $recurrence->dayofmonth);
        self::assertSame(2, $recurrence->weekofmonth);
        self::assertSame(11, $recurrence->monthofyear);
    }

    public function testBuildOmitsEmptyLists(): void
    {
        $event = new CalendarEvent();
        $event->setTitle('Plain');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));

        $appointment = new CalendarMapper()->buildSyncAppointment($event);

        self::assertNull($appointment->attendees);
        self::assertNull($appointment->exceptions);
        self::assertNull($appointment->categories);
        self::assertNull($appointment->recurrence);
    }

    public function testBuildSupportsExceptionWithoutDescription(): void
    {
        $event = new CalendarEvent();
        $event->setTitle('Series');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $event->setRecurrenceType(1);

        $exception = new CalendarEventException();
        $exception->setExceptionStartAt(new \DateTimeImmutable('2026-11-17 09:00'));
        $exception->setTitle('Short');
        $exception->setStartAt(new \DateTimeImmutable('2026-11-17 11:00'));
        $exception->setEndAt(new \DateTimeImmutable('2026-11-17 12:00'));
        $event->addException($exception);

        $appointment = new CalendarMapper()->buildSyncAppointment($event);

        $exceptions = $appointment->exceptions;
        self::assertIsArray($exceptions);
        self::assertCount(1, $exceptions);
        self::assertInstanceOf(\SyncAppointmentException::class, $exceptions[0]);
        self::assertNull($exceptions[0]->asbody);
    }

    public function testApplyRoundTripsDtstampAndFullRecurrencePattern(): void
    {
        $mapper = new CalendarMapper();
        $syncAppointment = $mapper->buildSyncAppointment(self::filledEvent());
        $applied = new CalendarEvent();
        $applied->setTitle('seed');
        $applied->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));

        $mapper->applySyncAppointment($applied, $syncAppointment);

        self::assertSame('2026-11-09 10:00', $applied->getDtstamp()?->format('Y-m-d H:i'));
        self::assertSame(15, $applied->getRecurrenceDayOfMonth());
        self::assertSame(2, $applied->getRecurrenceWeekOfMonth());
        self::assertSame(11, $applied->getRecurrenceMonthOfYear());
    }

    public function testApplyLeavesRecurrenceUntouchedWithoutRecurrenceBlock(): void
    {
        $event = new CalendarEvent();
        $event->setTitle('Series');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $event->setRecurrenceType(1);
        $event->setRecurrenceInterval(2);

        $appointment = new \SyncAppointment();
        $appointment->subject = 'Series';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();

        new CalendarMapper()->applySyncAppointment($event, $appointment);

        self::assertSame(1, $event->getRecurrenceType());
        self::assertSame(2, $event->getRecurrenceInterval());
    }

    public function testApplyIgnoresForeignAttendeeEntriesAndNonArrayCategories(): void
    {
        $attendee = new \SyncAttendee();
        $attendee->name = 'Bob Guest';
        $attendee->email = 'bob@example.com';

        $appointment = new \SyncAppointment();
        $appointment->subject = 'Event';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $appointment->attendees = [$attendee, 'garbage'];
        $appointment->categories = 'Work';

        $event = new CalendarEvent();
        $event->setTitle('seed');
        $event->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));
        $event->setCategories(['Keep']);

        new CalendarMapper()->applySyncAppointment($event, $appointment);

        $attendees = $event->getAttendees();
        self::assertNotNull($attendees);
        self::assertCount(1, $attendees);
        self::assertSame('bob@example.com', $attendees[0]['email']);
        self::assertNull($event->getCategories());
    }

    public function testApplySkipsExceptionsWithoutExceptionStartTime(): void
    {
        $appointment = new \SyncAppointment();
        $appointment->subject = 'Series';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $appointment->recurrence = new \SyncRecurrence();
        $appointment->recurrence->type = 1;

        $bogus = new \SyncAppointmentException();
        $bogus->deleted = 1;
        $appointment->exceptions = [$bogus];

        $event = new CalendarEvent();
        $event->setTitle('seed');
        $event->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));

        new CalendarMapper()->applySyncAppointment($event, $appointment);

        self::assertCount(0, $event->getExceptions());
    }

    public function testApplyAcceptsExceptionWithoutBody(): void
    {
        $exception = new \SyncAppointmentException();
        $exception->exceptionstarttime = new \DateTimeImmutable('2026-11-17 09:00')->getTimestamp();
        $exception->subject = 'Short';

        $appointment = new \SyncAppointment();
        $appointment->subject = 'Series';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $appointment->recurrence = new \SyncRecurrence();
        $appointment->recurrence->type = 1;
        $appointment->exceptions = [$exception];

        $event = new CalendarEvent();
        $event->setTitle('seed');
        $event->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));

        new CalendarMapper()->applySyncAppointment($event, $appointment);

        self::assertCount(1, $event->getExceptions());
        $stored = [...$event->getExceptions()][0];
        self::assertSame('Short', $stored->getTitle());
        self::assertNull($stored->getDescription());
    }

    public function testApplyDropsGarbageTimezoneBlob(): void
    {
        $appointment = new \SyncAppointment();
        $appointment->subject = 'Event';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $appointment->timezone = '!!!not-base64!!!';

        $event = new CalendarEvent();
        $event->setTitle('seed');
        $event->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));
        $event->setTimezone('Europe/Berlin');

        new CalendarMapper()->applySyncAppointment($event, $appointment);

        self::assertNull($event->getTimezone());
    }

    public function testBuildUsesLegacyBodyBelowProtocol12(): void
    {
        self::setProtocolVersion('2.5');

        $appointment = new CalendarMapper()->buildSyncAppointment(self::filledEvent());

        self::assertSame('Weekly sync', $appointment->body);
        self::assertNull($appointment->asbody);
    }

    public function testApplyRoundTripsEveryField(): void
    {
        $mapper = new CalendarMapper();
        $syncAppointment = $mapper->buildSyncAppointment(self::filledEvent());
        $applied = new CalendarEvent();
        $applied->setTitle('seed');
        $applied->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));

        $mapper->applySyncAppointment($applied, $syncAppointment);

        self::assertSame('Team meeting', $applied->getTitle());
        self::assertSame('2026-11-10', $applied->getStartAt()->format('Y-m-d'));
        self::assertSame('09:00', $applied->getStartAt()->format('H:i'));
        self::assertSame('2026-11-10 10:00', $applied->getEndAt()?->format('Y-m-d H:i'));
        self::assertTrue($applied->isAllDay());
        self::assertSame('Meeting room 1', $applied->getLocation());
        self::assertSame('Weekly sync', $applied->getDescription());
        self::assertSame('event-uid-1', $applied->getUid());
        self::assertSame(2, $applied->getBusyStatus());
        self::assertSame(1, $applied->getSensitivity());
        self::assertSame(15, $applied->getReminder());
        self::assertSame('Alice Organizer', $applied->getOrganizerName());
        self::assertSame('alice@example.com', $applied->getOrganizerEmail());
        self::assertSame(1, $applied->getMeetingStatus());
        self::assertSame(2, $applied->getResponseType());
        self::assertTrue($applied->getResponseRequested());
        self::assertTrue($applied->getDisallowNewTimeProposal());
        self::assertSame('https://conf.example.com/meet', $applied->getOnlineMeetingConfLink());
        self::assertSame('https://ext.example.com/meet', $applied->getOnlineMeetingExternalLink());
        self::assertSame(['Work', 'Team'], $applied->getCategories());
        self::assertSame(1, $applied->getRecurrenceType());
        self::assertSame(2, $applied->getRecurrenceInterval());
        self::assertSame(10, $applied->getRecurrenceOccurrences());
        self::assertSame(62, $applied->getRecurrenceDayOfWeek());
        self::assertSame(0, $applied->getRecurrenceCalendarType());
        self::assertSame(1, $applied->getRecurrenceFirstDayOfWeek());
        self::assertSame('2026-12-31', $applied->getRecurrenceUntil()?->format('Y-m-d'));

        $attendees = $applied->getAttendees();
        self::assertNotNull($attendees);
        self::assertCount(2, $attendees);
        self::assertSame('Bob Guest', $attendees[0]['name']);
        self::assertSame('bob@example.com', $attendees[0]['email']);
        self::assertSame(1, $attendees[0]['type']);
        self::assertSame(3, $attendees[0]['status']);

        $exceptions = $applied->getExceptions();
        self::assertCount(2, $exceptions);

        $stored = [...$exceptions];
        self::assertTrue($stored[0]->isDeleted());
        self::assertSame('2026-11-24 09:00', $stored[0]->getExceptionStartAt()->format('Y-m-d H:i'));
        self::assertFalse($stored[1]->isDeleted());
        self::assertSame('2026-12-08 09:00', $stored[1]->getExceptionStartAt()->format('Y-m-d H:i'));
        self::assertSame('Moved session', $stored[1]->getTitle());
        self::assertSame('2026-12-08 14:00', $stored[1]->getStartAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-12-08 15:00', $stored[1]->getEndAt()?->format('Y-m-d H:i'));
        self::assertSame('Room 2', $stored[1]->getLocation());
        self::assertSame('Moved note', $stored[1]->getDescription());
        self::assertSame(3, $stored[1]->getBusyStatus());
        self::assertSame(30, $stored[1]->getReminder());
    }

    public function testApplyReplacesPreviousExceptions(): void
    {
        $mapper = new CalendarMapper();
        $event = new CalendarEvent();
        $event->setTitle('Series');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $event->setRecurrenceType(1);

        $stale = new CalendarEventException();
        $stale->setExceptionStartAt(new \DateTimeImmutable('2026-11-17 09:00'));
        $event->addException($stale);

        $syncAppointment = new \SyncAppointment();
        $syncAppointment->subject = 'Series';
        $syncAppointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $syncAppointment->recurrence = new \SyncRecurrence();
        $syncAppointment->recurrence->type = 1;
        $syncAppointment->exceptions = [
            self::deletedException('2026-11-17 09:00'),
        ];

        $mapper->applySyncAppointment($event, $syncAppointment);

        self::assertCount(1, $event->getExceptions());
        $remaining = [...$event->getExceptions()][0];
        self::assertTrue($remaining->isDeleted());
        self::assertSame('2026-11-17 09:00', $remaining->getExceptionStartAt()->format('Y-m-d H:i'));
    }

    public function testApplyReadsLegacyBodyDescription(): void
    {
        self::setProtocolVersion('2.5');

        $appointment = new \SyncAppointment();
        $appointment->subject = 'Event';
        $appointment->starttime = new \DateTimeImmutable('2026-11-10 09:00')->getTimestamp();
        $appointment->body = 'Legacy description';

        $event = new CalendarEvent();
        $event->setTitle('seed');
        $event->setStartAt(new \DateTimeImmutable('2026-01-01 00:00'));
        new CalendarMapper()->applySyncAppointment($event, $appointment);

        self::assertSame('Legacy description', $event->getDescription());
    }

    private static function deletedException(string $start): \SyncAppointmentException
    {
        $exception = new \SyncAppointmentException();
        $exception->exceptionstarttime = new \DateTimeImmutable($start)->getTimestamp();
        $exception->deleted = 1;

        return $exception;
    }

    private static function filledEvent(): CalendarEvent
    {
        $event = new CalendarEvent();
        $event->setTitle('Team meeting');
        $event->setLocation('Meeting room 1');
        $event->setDescription('Weekly sync');
        $event->setStartAt(new \DateTimeImmutable('2026-11-10 09:00'));
        $event->setEndAt(new \DateTimeImmutable('2026-11-10 10:00'));
        $event->setIsAllDay(true);
        $event->setUid('event-uid-1');
        $event->setDtstamp(new \DateTimeImmutable('2026-11-09 10:00'));
        $event->setBusyStatus(2);
        $event->setSensitivity(1);
        $event->setReminder(15);
        $event->setOrganizerName('Alice Organizer');
        $event->setOrganizerEmail('alice@example.com');
        $event->setMeetingStatus(1);
        $event->setResponseType(2);
        $event->setResponseRequested(true);
        $event->setDisallowNewTimeProposal(true);
        $event->setOnlineMeetingConfLink('https://conf.example.com/meet');
        $event->setOnlineMeetingExternalLink('https://ext.example.com/meet');
        $event->setCategories(['Work', 'Team']);
        $event->setRecurrenceType(1);
        $event->setRecurrenceInterval(2);
        $event->setRecurrenceUntil(new \DateTimeImmutable('2026-12-31 00:00'));
        $event->setRecurrenceOccurrences(10);
        $event->setRecurrenceDayOfWeek(62);
        $event->setRecurrenceDayOfMonth(15);
        $event->setRecurrenceWeekOfMonth(2);
        $event->setRecurrenceMonthOfYear(11);
        $event->setRecurrenceCalendarType(0);
        $event->setRecurrenceFirstDayOfWeek(1);
        $event->setAttendees([
            ['name' => 'Bob Guest', 'email' => 'bob@example.com', 'type' => 1, 'status' => 3],
            ['name' => 'Carol Optional', 'email' => 'carol@example.com', 'type' => null, 'status' => null],
        ]);

        $deleted = new CalendarEventException();
        $deleted->setExceptionStartAt(new \DateTimeImmutable('2026-11-24 09:00'));
        $deleted->setIsDeleted(true);
        $event->addException($deleted);

        $moved = new CalendarEventException();
        $moved->setExceptionStartAt(new \DateTimeImmutable('2026-12-08 09:00'));
        $moved->setTitle('Moved session');
        $moved->setStartAt(new \DateTimeImmutable('2026-12-08 14:00'));
        $moved->setEndAt(new \DateTimeImmutable('2026-12-08 15:00'));
        $moved->setLocation('Room 2');
        $moved->setDescription('Moved note');
        $moved->setBusyStatus(3);
        $moved->setReminder(30);
        $event->addException($moved);

        return $event;
    }

    private static function setProtocolVersion(?string $version): void
    {
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, $version);
    }
}
