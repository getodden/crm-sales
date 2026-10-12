<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;

/**
 * Computes the open booking slots of a meeting link.
 *
 * Working hours are a map of lowercase English day names to lists of "HH:MM-HH:MM" windows in the
 * link's timezone, for example ['monday' => ['09:00-12:00', '13:00-17:00']]. Days that are missing
 * or empty have no slots. A link without working hours uses odden-sales.meetings.default_working_hours.
 *
 * Slots start at the beginning of each window and repeat every duration + buffer minutes while the
 * meeting still ends inside the window. Slots in the past, beyond the booking window, or overlapping
 * one of the host's active bookings (on any of their links, widened by this link's buffer) are left out.
 */
class MeetingAvailability
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The link's working hours, normalized to day => list of [startMinutes, endMinutes].
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    public function workingHours(SalesMeetingLink $link): array
    {
        $hours = $link->working_hours;

        if (! is_array($hours) || $hours === []) {
            $hours = config('odden-sales.meetings.default_working_hours', []);
        }

        $normalized = [];

        foreach (is_array($hours) ? $hours : [] as $day => $windows) {
            $day = strtolower((string) $day);

            if (! in_array($day, self::DAYS, true)) {
                continue;
            }

            foreach (is_array($windows) ? $windows : [$windows] as $window) {
                $parsed = is_string($window) ? self::parseWindow($window) : null;

                if ($parsed !== null) {
                    $normalized[$day][] = $parsed;
                }
            }
        }

        return $normalized;
    }

    /**
     * Open slot start times on a date, in the link's timezone, earliest first.
     *
     * @param  CarbonInterface|string  $date  A Y-m-d date in the link's timezone (a Carbon instance's own date is used).
     * @param  list<array{0: int, 1: int}>|null  $busy  The host's bookings covering that day, when already loaded.
     * @return list<CarbonImmutable>
     */
    public function slotsFor(SalesMeetingLink $link, CarbonInterface|string $date, ?array $busy = null): array
    {
        $timezone = $link->timezoneName();
        $dateString = $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString) !== 1) {
            return [];
        }

        $day = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$dateString} 00:00:00", $timezone);

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $dateString) {
            return [];
        }

        $now = CarbonImmutable::now($timezone);

        if ($day->lt($now->startOfDay()) || $day->gt($this->lastBookableDate($link))) {
            return [];
        }

        $windows = $this->workingHours($link)[strtolower($day->englishDayOfWeek)] ?? [];
        $duration = max(1, $link->duration_minutes);
        $buffer = max(0, $link->buffer_minutes);

        $candidates = [];

        foreach ($windows as [$startMinutes, $endMinutes]) {
            // setTime() keeps wall-clock times right on daylight-saving change days.
            $windowEnd = $endMinutes === 24 * 60 ? $day->addDay() : $day->setTime(intdiv($endMinutes, 60), $endMinutes % 60);
            $slot = $day->setTime(intdiv($startMinutes, 60), $startMinutes % 60);

            while ($slot->addMinutes($duration)->lte($windowEnd)) {
                if ($slot->gt($now)) {
                    $candidates[$slot->getTimestamp()] = $slot;
                }

                $slot = $slot->addMinutes($duration + $buffer);
            }
        }

        if ($candidates === []) {
            return [];
        }

        ksort($candidates);
        $busy ??= $this->busyPeriods($link, $day->subMinutes($buffer), $day->addDay()->addMinutes($buffer));

        $open = [];

        foreach ($candidates as $start => $slot) {
            $end = $start + $duration * 60;

            foreach ($busy as [$busyStart, $busyEnd]) {
                if ($start < $busyEnd + $buffer * 60 && $end + $buffer * 60 > $busyStart) {
                    continue 2;
                }
            }

            $open[] = $slot;
        }

        return $open;
    }

    /**
     * Whether a meeting can be booked starting exactly at $startsAt.
     */
    public function isAvailable(SalesMeetingLink $link, CarbonInterface $startsAt): bool
    {
        $local = CarbonImmutable::instance($startsAt)->setTimezone($link->timezoneName());

        foreach ($this->slotsFor($link, $local->format('Y-m-d')) as $slot) {
            if ($slot->getTimestamp() === $local->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first date (Y-m-d, link timezone) with an open slot, or null if none in the booking window.
     */
    public function firstAvailableDate(SalesMeetingLink $link): ?string
    {
        $date = CarbonImmutable::now($link->timezoneName())->startOfDay();
        $last = $this->lastBookableDate($link);

        // The host's bookings over the whole window in one query, rather than one query per day.
        $buffer = max(0, $link->buffer_minutes);
        $busy = $this->busyPeriods($link, $date->subMinutes($buffer), $last->addDay()->addMinutes($buffer));

        while ($date->lte($last)) {
            if ($this->slotsFor($link, $date->format('Y-m-d'), $busy) !== []) {
                return $date->format('Y-m-d');
            }

            $date = $date->addDay();
        }

        return null;
    }

    /**
     * The last date visitors can book, at the start of that day in the link's timezone.
     */
    public function lastBookableDate(SalesMeetingLink $link): CarbonImmutable
    {
        $days = max(0, (int) config('odden-sales.meetings.booking_window_days', 60));

        return CarbonImmutable::now($link->timezoneName())->startOfDay()->addDays($days);
    }

    /**
     * Parse "HH:MM-HH:MM" into minutes from midnight. The end may be 24:00.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseWindow(string $window): ?array
    {
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $window, $m) !== 1) {
            return null;
        }

        $start = (int) $m[1] * 60 + (int) $m[2];
        $end = (int) $m[3] * 60 + (int) $m[4];

        if ((int) $m[2] > 59 || (int) $m[4] > 59 || $start >= $end || $end > 24 * 60) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * The host's active bookings overlapping a period, as [start, end] Unix timestamps.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function busyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Datetime columns are stored in the app's timezone, so compare in it.
        $appTimezone = (string) config('app.timezone', 'UTC');

        return array_values(SalesMeetingBooking::query()
            ->active()
            ->where('user_id', $link->user_id)
            ->where('starts_at', '<', $to->setTimezone($appTimezone)->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $from->setTimezone($appTimezone)->format('Y-m-d H:i:s'))
            ->get(['starts_at', 'ends_at'])
            ->map(fn (SalesMeetingBooking $booking): array => [$booking->starts_at->getTimestamp(), $booking->ends_at->getTimestamp()])
            ->all());
    }
}
