<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Odden\Sales\Contracts\ExternalBusyTime;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Throwable;

class MeetingAvailability
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(protected ExternalBusyTime $externalBusyTime) {}

    /**
     * @return array<string, list<string>>|null
     */
    public static function normalizeWorkingHours(mixed $hours): ?array
    {
        if (! is_array($hours)) {
            return null;
        }

        $normalized = [];

        foreach (MeetingAvailability::DAYS as $day) {
            $windows = $hours[$day] ?? [];

            if (! is_array($windows)) {
                continue;
            }

            $windows = array_values(array_filter(
                array_map(fn (mixed $window): string => is_string($window) ? trim($window) : '', $windows),
                fn (string $window): bool => $window !== '',
            ));

            if ($windows !== []) {
                $normalized[$day] = $windows;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
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
     * @param  list<array{0: int, 1: int}>|null  $busy
     * @return list<CarbonImmutable>
     */
    public function slotsFor(SalesMeetingLink $link, CarbonInterface|string $date, ?array $busy = null): array
    {
        return $this->openSlots($link, $date, $busy, true);
    }

    public function isAvailable(SalesMeetingLink $link, CarbonInterface $startsAt, bool $withExternal = true): bool
    {
        $local = CarbonImmutable::instance($startsAt)->setTimezone($link->timezoneName());

        foreach ($this->openSlots($link, $local->format('Y-m-d'), null, $withExternal) as $slot) {
            if ($slot->getTimestamp() === $local->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: int}>|null  $busy
     * @return list<CarbonImmutable>
     */
    protected function openSlots(SalesMeetingLink $link, CarbonInterface|string $date, ?array $busy, bool $withExternal): array
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
        $from = $day->subMinutes($buffer);
        $to = $day->addDay()->addMinutes($buffer);
        $busy ??= $withExternal ? $this->allBusyPeriods($link, $from, $to) : $this->busyPeriods($link, $from, $to);

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

    public function firstAvailableDate(SalesMeetingLink $link): ?string
    {
        $date = CarbonImmutable::now($link->timezoneName())->startOfDay();
        $last = $this->lastBookableDate($link);

        $buffer = max(0, $link->buffer_minutes);
        $busy = $this->allBusyPeriods($link, $date->subMinutes($buffer), $last->addDay()->addMinutes($buffer));

        while ($date->lte($last)) {
            if ($this->slotsFor($link, $date->format('Y-m-d'), $busy) !== []) {
                return $date->format('Y-m-d');
            }

            $date = $date->addDay();
        }

        return null;
    }

    public function lastBookableDate(SalesMeetingLink $link): CarbonImmutable
    {
        $days = max(0, (int) config('odden-sales.meetings.booking_window_days', 60));

        return CarbonImmutable::now($link->timezoneName())->startOfDay()->addDays($days);
    }

    /**
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
     * @return list<array{0: int, 1: int}>
     */
    protected function allBusyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [...$this->busyPeriods($link, $from, $to), ...$this->externalPeriods($link, $from, $to)];
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    protected function externalPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            return $this->externalBusyTime->busyPeriods($link, $from, $to);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    protected function busyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
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
