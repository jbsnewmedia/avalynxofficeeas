<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\CalendarEvent;

/**
 * Maps CalendarEvent models to EAS SyncAppointment objects and back.
 */
final class CalendarMapper
{
    /**
     * @return array{id: string, mod: string, flags: int, star: int}
     */
    public function stat(CalendarEvent $event): array
    {
        return [
            'id' => (string) $event->getId()?->toRfc4122(),
            'mod' => (string) ($event->getUpdatedAt()?->getTimestamp() ?? 0),
            'flags' => 0,
            'star' => 0,
        ];
    }

    public function buildSyncAppointment(CalendarEvent $event): \SyncAppointment
    {
        $appointment = new \SyncAppointment();
        $appointment->subject = $event->getTitle();
        $appointment->alldayevent = $event->isAllDay() ? 1 : 0;
        $appointment->starttime = $event->getStartAt()->getTimestamp();

        if (null !== $event->getEndAt()) {
            $appointment->endtime = $event->getEndAt()->getTimestamp();
        }

        if (null !== $event->getLocation() && '' !== $event->getLocation()) {
            $appointment->location = $event->getLocation();
        }

        if (null !== $event->getDescription() && '' !== $event->getDescription()) {
            $appointment->body = $event->getDescription();
        }

        if (null !== $event->getUid() && '' !== $event->getUid()) {
            $appointment->uid = $event->getUid();
        }

        if (null !== $event->getDtstamp()) {
            $appointment->dtstamp = $event->getDtstamp()->getTimestamp();
        }

        if (null !== $event->getBusyStatus()) {
            $appointment->busystatus = $event->getBusyStatus();
        }

        if (null !== $event->getSensitivity()) {
            $appointment->sensitivity = $event->getSensitivity();
        }

        if (null !== $event->getReminder()) {
            $appointment->reminder = $event->getReminder();
        }

        $appointment->timezone = $this->timezoneBlob(
            $event->getTimezone() ?? date_default_timezone_get(),
        );

        if (null !== $event->getRecurrenceType()) {
            $appointment->recurrence = $this->buildRecurrence($event);
        }

        return $appointment;
    }

    public function applySyncAppointment(CalendarEvent $event, \SyncAppointment $appointment): void
    {
        if (isset($appointment->subject)) {
            $title = trim(is_string($appointment->subject) ? $appointment->subject : '');

            if ('' !== $title) {
                $event->setTitle($title);
            }
        }

        if (isset($appointment->starttime)) {
            $event->setStartAt($this->fromTimestamp($appointment->starttime));
        }

        if (isset($appointment->endtime)) {
            $event->setEndAt($this->fromTimestamp($appointment->endtime));
        }

        if (isset($appointment->alldayevent)) {
            $event->setIsAllDay((bool) $appointment->alldayevent);
        }

        if (isset($appointment->location)) {
            $location = trim(is_string($appointment->location) ? $appointment->location : '');
            $event->setLocation('' !== $location ? $location : null);
        }

        if (isset($appointment->body)) {
            $body = trim(is_string($appointment->body) ? $appointment->body : '');
            $event->setDescription('' !== $body ? $body : null);
        }

        if (isset($appointment->timezone) && is_string($appointment->timezone) && '' !== $appointment->timezone) {
            $event->setTimezone($this->timezoneFromBlob($appointment->timezone));
        }

        if (isset($appointment->uid) && is_string($appointment->uid) && '' !== trim($appointment->uid)) {
            $event->setUid(trim($appointment->uid));
        }

        if (isset($appointment->dtstamp)) {
            $event->setDtstamp($this->fromTimestamp($appointment->dtstamp));
        }

        if (isset($appointment->busystatus)) {
            $event->setBusyStatus($this->intOrNull($appointment->busystatus));
        }

        if (isset($appointment->sensitivity)) {
            $event->setSensitivity($this->intOrNull($appointment->sensitivity));
        }

        if (isset($appointment->reminder)) {
            $event->setReminder($this->intOrNull($appointment->reminder));
        }

        $this->applyRecurrence($event, $appointment);
    }

    private function buildRecurrence(CalendarEvent $event): \SyncRecurrence
    {
        $recurrence = new \SyncRecurrence();
        $recurrence->type = $event->getRecurrenceType() ?? 0;

        if (null !== $event->getRecurrenceInterval() && $event->getRecurrenceInterval() > 1) {
            $recurrence->interval = $event->getRecurrenceInterval();
        }

        if (null !== $event->getRecurrenceUntil()) {
            $recurrence->until = $event->getRecurrenceUntil()->getTimestamp();
        }

        if (null !== $event->getRecurrenceDayOfWeek()) {
            $recurrence->dayofweek = $event->getRecurrenceDayOfWeek();
        }

        if (null !== $event->getRecurrenceDayOfMonth()) {
            $recurrence->dayofmonth = $event->getRecurrenceDayOfMonth();
        }

        if (null !== $event->getRecurrenceWeekOfMonth()) {
            $recurrence->weekofmonth = $event->getRecurrenceWeekOfMonth();
        }

        if (null !== $event->getRecurrenceMonthOfYear()) {
            $recurrence->monthofyear = $event->getRecurrenceMonthOfYear();
        }

        return $recurrence;
    }

    private function applyRecurrence(CalendarEvent $event, \SyncAppointment $appointment): void
    {
        if (!isset($appointment->recurrence) || !$appointment->recurrence instanceof \SyncRecurrence) {
            return;
        }

        $recurrence = $appointment->recurrence;
        $event->setRecurrenceType($this->intOrNull($recurrence->type ?? null));
        $event->setRecurrenceInterval($this->intOrNull($recurrence->interval ?? null));

        if (isset($recurrence->until)) {
            $event->setRecurrenceUntil($this->fromTimestamp($recurrence->until));
        } else {
            $event->setRecurrenceUntil(null);
        }

        $event->setRecurrenceDayOfWeek($this->intOrNull($recurrence->dayofweek ?? null));
        $event->setRecurrenceDayOfMonth($this->intOrNull($recurrence->dayofmonth ?? null));
        $event->setRecurrenceWeekOfMonth($this->intOrNull($recurrence->weekofmonth ?? null));
        $event->setRecurrenceMonthOfYear($this->intOrNull($recurrence->monthofyear ?? null));
    }

    private function timezoneBlob(string $timezoneName): string
    {
        return base64_encode(\TimezoneUtil::GetSyncBlobFromTZ(
            \TimezoneUtil::GetFullTZFromTZName($timezoneName),
        ));
    }

    private function timezoneFromBlob(string $blob): ?string
    {
        $raw = base64_decode($blob, true);

        // A full TZ struct is 172 bytes (4 + 64 + 16 + 4 + 64 + 16 + 4);
        // anything shorter is garbage and yields no usable timezone.
        if (false === $raw || strlen($raw) < 172) {
            return null;
        }

        $unpacked = unpack('lbias', substr($raw, 0, 4));
        \assert(is_array($unpacked) && is_int($unpacked['bias']));

        // The bias is the minutes to ADD to local time to get UTC, so the
        // UTC offset is its negative.
        $offsetMinutes = -1 * $unpacked['bias'];

        return sprintf('%s%02d:%02d', $offsetMinutes >= 0 ? '+' : '-', intdiv(abs($offsetMinutes), 60), abs($offsetMinutes) % 60);
    }

    private function fromTimestamp(mixed $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable()->setTimestamp(is_numeric($timestamp) ? (int) $timestamp : 0);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
