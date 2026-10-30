<?php

declare(strict_types=1);

namespace Odden\Sales\Contracts;

use Carbon\CarbonImmutable;
use Odden\Sales\Models\SalesMeetingLink;

/**
 * Supplies time the host is busy outside this app, such as meetings in an external calendar, so meeting
 * links do not offer or accept slots that clash with it.
 *
 * The default implementation returns nothing. Bind another implementation to feed in the host's
 * external calendar. MeetingAvailability merges these periods with the host's own bookings, and the
 * link's buffer widens them the same way.
 *
 * Implementations must not throw and should return quickly: this runs on the public booking page and
 * again when a booking is made. Decide inside the implementation what a provider outage means, for
 * example by returning the last known periods. If one throws anyway, MeetingAvailability reports it
 * and treats the host as free of external periods.
 */
interface ExternalBusyTime
{
    /**
     * The host's external busy periods overlapping the window, as [start, end] Unix timestamps.
     * Periods may touch, overlap, or reach outside the window.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function busyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array;
}
