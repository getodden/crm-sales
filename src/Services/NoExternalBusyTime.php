<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Carbon\CarbonImmutable;
use Odden\Sales\Contracts\ExternalBusyTime;
use Odden\Sales\Models\SalesMeetingLink;

/**
 * The default ExternalBusyTime: the host has no busy time outside this app.
 */
class NoExternalBusyTime implements ExternalBusyTime
{
    public function busyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
