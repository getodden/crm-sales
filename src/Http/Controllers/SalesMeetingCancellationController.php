<?php

declare(strict_types=1);

namespace Odden\Sales\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Models\SalesMeetingBooking;

/**
 * The invitee's cancel page. The URL is signed (the booking's uid and a signature made with the app key), so it
 * cannot be guessed or edited, and anything that fails the signature is a 404. Opening it never cancels: only the POST does.
 */
class SalesMeetingCancellationController extends Controller
{
    public function show(Request $request, string $uid): View
    {
        $booking = $this->booking($request, $uid);

        return view('odden-sales::meetings.cancel', [
            'booking' => $booking,
            'link' => $booking->meetingLink,
            'state' => $this->state($booking),
            'startsAt' => $booking->starts_at->copy()->setTimezone($booking->timezone),
            'endsAt' => $booking->ends_at->copy()->setTimezone($booking->timezone),
        ]);
    }

    public function cancel(Request $request, string $uid): RedirectResponse
    {
        $booking = $this->booking($request, $uid);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($this->state($booking) === 'open') {
            $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : '';

            $booking->cancel(MeetingCancelledBy::Invitee, reason: $reason !== '' ? $reason : null);
        }

        return redirect()->to($request->fullUrl());
    }

    private function booking(Request $request, string $uid): SalesMeetingBooking
    {
        abort_unless($request->hasValidSignature(), 404);

        return SalesMeetingBooking::query()
            ->where('uid', $uid)
            ->with(['meetingLink', 'user'])
            ->firstOrFail();
    }

    /**
     * 'cancelled' once cancelled, 'past' once the meeting has started, otherwise 'open'.
     */
    private function state(SalesMeetingBooking $booking): string
    {
        return match (true) {
            $booking->cancelled_at !== null => 'cancelled',
            $booking->starts_at->lte(now()) => 'past',
            default => 'open',
        };
    }
}
