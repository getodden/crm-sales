<?php

declare(strict_types=1);

namespace Odden\Sales\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Exceptions\MeetingBookingLimitException;
use Odden\Sales\Exceptions\MeetingSlotUnavailableException;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;

class SalesMeetingBookingController extends Controller
{
    /**
     * Display rep's public meeting booking page with the open slots for one date.
     */
    public function show(Request $request, string $slug, MeetingAvailability $availability): View
    {
        /** @var SalesMeetingLink $link */
        $link = SalesMeetingLink::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with('user')
            ->firstOrFail();

        $timezone = $link->timezoneName();
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        $maxDate = $availability->lastBookableDate($link)->format('Y-m-d');

        $requested = $request->query('date', old('date'));
        $date = is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) === 1
            ? $requested
            : ($availability->firstAvailableDate($link) ?? $today);

        $slots = $availability->slotsFor($link, $date);

        return view('odden-sales::meetings.book', [
            'link' => $link,
            'date' => $date,
            'slots' => $slots,
            'timezone' => $timezone,
            'minDate' => $today,
            'maxDate' => $maxDate,
        ]);
    }

    /**
     * Book meeting slot with rep.
     */
    public function book(Request $request, string $slug, BookMeetingAction $action): RedirectResponse
    {
        /** @var SalesMeetingLink $link */
        $link = SalesMeetingLink::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $timezone = $link->timezoneName();
        $scheduledAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$validated['date']} {$validated['time']}", $timezone);

        try {
            if (! $scheduledAt instanceof CarbonImmutable) {
                throw new MeetingSlotUnavailableException('Invalid meeting time.');
            }

            $result = $action->execute(
                link: $link,
                fullName: (string) $validated['name'],
                email: (string) $validated['email'],
                scheduledAt: $scheduledAt,
                phone: isset($validated['phone']) ? (string) $validated['phone'] : null,
                notes: isset($validated['notes']) ? (string) $validated['notes'] : null
            );
        } catch (MeetingSlotUnavailableException) {
            return back()
                ->withInput()
                ->withErrors(['time' => 'That time is no longer available. Please choose another slot.']);
        } catch (MeetingBookingLimitException $e) {
            return back()
                ->withInput()
                ->withErrors(['email' => $e->getMessage()]);
        }

        $when = $scheduledAt->format('l, F j \a\t g:i A');

        return redirect()
            ->route('odden.meetings.show', ['slug' => $slug])
            ->with('status', "Meeting booked for {$when} ({$timezone}). A confirmation with a calendar invite is on its way to {$result['booking']->invitee_email}.");
    }
}
