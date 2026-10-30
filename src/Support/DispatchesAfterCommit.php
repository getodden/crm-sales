<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Dispatches an event once the open transaction (if any) commits. A listener that throws is
 * reported and does not reach the caller, so it can neither undo a booking nor turn a committed
 * booking into an error page. Each listener runs on its own, so one that throws does not keep the
 * listeners after it from running. A listener that returns false still stops the ones after it, as
 * with Event::dispatch, and a queued listener is still queued, not run inline.
 */
final class DispatchesAfterCommit
{
    public static function dispatch(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            $dispatcher = Event::getFacadeRoot();

            if (! $dispatcher instanceof Dispatcher) {
                self::attempt(static fn () => Event::dispatch($event));

                return;
            }

            foreach ($dispatcher->getListeners($event::class) as $listener) {
                if (self::attempt(static fn () => $listener($event::class, [$event])) === false) {
                    break;
                }
            }
        });
    }

    private static function attempt(callable $run): mixed
    {
        try {
            return $run();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
