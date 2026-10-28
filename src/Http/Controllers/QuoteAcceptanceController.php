<?php

declare(strict_types=1);

namespace Odden\Sales\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Support\ActivityMarker;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Models\Quote;

class QuoteAcceptanceController extends Controller
{
    /**
     * Display the public customer quote acceptance portal.
     */
    public function show(string $token): View
    {
        /** @var Quote $quote */
        $quote = Quote::query()
            ->where('public_token', $token)
            ->with(['deal.contacts.companies', 'items'])
            ->firstOrFail();

        // A quote whose deal has been deleted has nothing to show or to sign.
        abort_if($quote->getRelation('deal') === null, 404);

        // A draft is not ready for the customer: it is not shown, and opening the link never
        // changes the quote's status (a staff preview or a link scanner must not make it signable).
        abort_if($quote->status === QuoteStatus::Draft, 404);

        // Log portal viewing activity on the deal (throttled to once every 2 hours per quote)
        $recentView = $quote->deal->activities()
            ->where('marker', ActivityMarker::quotePortalView($quote->id))
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();

        if (! $recentView) {
            $quote->deal->logActivity(
                type: ActivityType::Note,
                title: 'Proposal Viewed by Customer',
                body: "Quote #{$quote->quote_number} (\"{$quote->title}\") was opened and viewed via the customer portal.",
                metadata: [
                    'quote_id' => $quote->id,
                    'event' => 'portal_view',
                    'ip' => request()->ip(),
                ]
            );
        }

        return view('odden-sales::quotes.public-portal', compact('quote'));
    }

    /**
     * Process quote acceptance and e-signature.
     */
    public function accept(Request $request, string $token, AcceptQuoteAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'signed_name' => ['required', 'string', 'min:2', 'max:255'],
            'signed_email' => ['required', 'email', 'max:255'],
            'agree_terms' => ['accepted'],
        ]);

        try {
            $action->execute(
                token: $token,
                signedByName: (string) $validated['signed_name'],
                signedByEmail: (string) $validated['signed_email']
            );

            return redirect()
                ->route('odden.quotes.show', ['token' => $token])
                ->with('status', 'Thank you. The quote is accepted and signed.');
        } catch (QuoteNotAcceptableException $e) {
            return redirect()
                ->route('odden.quotes.show', ['token' => $token])
                ->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Internal failures (e.g. a won-stage requirement) are logged; the public visitor gets a generic message.
            report($e);

            return redirect()
                ->route('odden.quotes.show', ['token' => $token])
                ->withErrors(['error' => 'We could not complete the acceptance of this proposal. Please contact your sales representative.']);
        }
    }
}
