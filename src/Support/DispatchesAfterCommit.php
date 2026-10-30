<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Dispatches an event once the open transaction (if any) commits. A listener that throws is
 * reported and does not reach the caller, so it can neither undo a booking nor turn a committed
 * booking into an error page.
 */
final class DispatchesAfterCommit
{
    public static function dispatch(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                Event::dispatch($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
