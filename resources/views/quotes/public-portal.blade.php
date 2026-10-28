<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $quote->title }} - Quote #{{ $quote->quote_number }}</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            line-height: 1.5;
            padding: 2rem 1rem;
        }

        .portal-container {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 0.75rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .portal-header {
            padding: 2.25rem 2.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1.25rem;
            background: #fafaf9;
        }

        .portal-title-block h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.025em;
        }

        .portal-title-block p {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 700;
        }

        .status-badge.accepted {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .status-badge.sent {
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .status-badge.expired {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .portal-body {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .parties-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1.5rem;
        }

        @media (min-width: 640px) {
            .parties-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        .party-col {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 1.25rem;
        }

        .party-col h2 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .party-col .name {
            font-size: 1.0625rem;
            font-weight: 700;
            color: #0f172a;
        }

        .party-col .detail {
            font-size: 0.875rem;
            color: #475569;
            margin-top: 0.125rem;
        }

        /* Items Table */
        .items-scroll {
            overflow-x: auto;
        }

        @media (max-width: 639px) {
            .portal-body {
                padding: 1.25rem;
            }
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .items-table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .items-table .num-col {
            text-align: right;
        }

        .item-name {
            font-weight: 700;
            color: #0f172a;
        }

        .item-desc {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Summary Total */
        .summary-wrap {
            display: flex;
            justify-content: flex-end;
        }

        .summary-box {
            width: 100%;
            max-width: 320px;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #64748b;
        }

        .summary-row.grand-total {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            border-top: 2px solid #e2e8f0;
            padding-top: 0.75rem;
            margin-top: 0.25rem;
        }

        /* Terms & Notes */
        .terms-card {
            background: #fafaf9;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 1.25rem;
            font-size: 0.8125rem;
            color: #475569;
        }

        .terms-card h3 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        /* Acceptance Signature Section */
        .signature-section {
            border: 2px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 2rem;
            background: #ffffff;
        }

        .signature-section.signed {
            background: #f0fdf4;
            border-color: #86efac;
        }

        .sig-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }

        .sig-subtitle {
            font-size: 0.8125rem;
            color: #64748b;
            margin-bottom: 1.5rem;
        }

        .sig-form-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .sig-form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .sig-input-group {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .sig-input-group label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #334155;
        }

        .sig-input {
            padding: 0.625rem 0.875rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .sig-input:focus {
            border-color: var(--odden-accent, #2563eb);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .sig-preview-box {
            grid-column: span 1;
            padding: 1rem;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 0.375rem;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Brush Script MT", "Caveat", "Segoe Script", cursive;
            font-size: 1.75rem;
            color: #1e3a8a;
        }

        @media (min-width: 640px) {
            .sig-preview-box {
                grid-column: span 2;
            }
        }

        .btn-accept {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            background: var(--odden-accent, #0d9488);
            color: #ffffff;
            font-size: 0.9375rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: background 0.15s ease;
            width: 100%;
        }

        .btn-accept:hover {
            background: var(--odden-accent-hover, #0f766e);
        }

        .alert-banner {
            padding: 1rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .portal-container {
                border: none;
                box-shadow: none;
            }
            .signature-section form {
                display: none;
            }
        }
    </style>
    @include('odden-sales::partials.branding')
</head>
<body>
    <div class="portal-container">
        {{-- Header --}}
        <div class="portal-header">
            <div class="portal-title-block">
                @include('odden-sales::partials.logo')
                <h1>{{ $quote->title }}</h1>
                <p>Proposal #{{ $quote->quote_number }} from {{ config('app.name') }}</p>
            </div>
            <div>
                @if ($quote->status === \Odden\Sales\Enums\QuoteStatus::Accepted)
                    <span class="status-badge accepted">&#10003; Accepted & Signed</span>
                @elseif ($quote->status === \Odden\Sales\Enums\QuoteStatus::Declined)
                    <span class="status-badge expired">Declined</span>
                @elseif (! $quote->isAcceptable())
                    <span class="status-badge expired">Expired</span>
                @else
                    <span class="status-badge sent">Awaiting Acceptance</span>
                @endif
            </div>
        </div>

        <div class="portal-body">
            @if (session('status'))
                <div class="alert-banner alert-success">
                    &#10003; {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert-banner alert-danger">
                    &#9888; {{ $errors->first() }}
                </div>
            @endif

            {{-- Parties: Prepared For & Details --}}
            @php
                $contact = $quote->deal?->contacts->first();
                $company = $contact?->companies->first();
                $rep = $quote->user ?? $quote->deal?->owner;
            @endphp
            <div class="parties-grid">
                {{-- Who the proposal is from: the business, and the person to ask about it. --}}
                <div class="party-col">
                    <h2>From</h2>
                    <div class="name">{{ config('app.name') }}</div>
                    @if ($rep)
                        <div class="detail">{{ $rep->name }}</div>
                        <div class="detail">{{ $rep->email }}</div>
                    @endif
                </div>

                <div class="party-col">
                    <h2>Prepared For</h2>
                    <div class="name">{{ $contact?->full_name ?? 'Client Representative' }}</div>
                    @if ($company)
                        <div class="detail">{{ $company->name }}</div>
                    @endif
                    <div class="detail">{{ $contact?->email }}</div>
                    @if ($contact?->phone)
                        <div class="detail">{{ $contact->phone }}</div>
                    @endif
                </div>

                <div class="party-col">
                    <h2>Proposal Details</h2>
                    <div class="detail"><strong>Date Issued:</strong> {{ $quote->created_at?->format('F j, Y') ?? now()->format('F j, Y') }}</div>
                    @if ($quote->expires_at)
                        <div class="detail"><strong>Valid Through:</strong> {{ $quote->expires_at->format('F j, Y') }}</div>
                    @endif
                    <div class="detail"><strong>Currency:</strong> {{ strtoupper($quote->currency) }}</div>
                </div>
            </div>

            {{-- Line Items Table: scrolls sideways on a narrow screen rather than cutting off the totals. --}}
            <div class="items-scroll">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item & Description</th>
                            <th class="num-col">Qty</th>
                            <th class="num-col">Unit Price</th>
                            <th class="num-col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($quote->items as $item)
                            <tr>
                                <td>
                                    <div class="item-name">{{ $item->name }}</div>
                                    @if ($item->description)
                                        <div class="item-desc">{{ $item->description }}</div>
                                    @endif
                                </td>
                                <td class="num-col">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}</td>
                                <td class="num-col">{{ $quote->currency }} {{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="num-col" style="font-weight: 700;">
                                    {{ $quote->currency }} {{ number_format((float) $item->total_price, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align: center; color: #94a3b8; padding: 2rem;">
                                    No itemized line items on this proposal.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Summary Subtotals --}}
            <div class="summary-wrap">
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span>{{ $quote->currency }} {{ number_format((float) $quote->subtotal, 2) }}</span>
                    </div>
                    @if ($quote->discount_amount > 0)
                        <div class="summary-row" style="color: #16a34a;">
                            <span>Discount</span>
                            <span>-{{ $quote->currency }} {{ number_format((float) $quote->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    @if ($quote->tax_amount > 0)
                        <div class="summary-row">
                            <span>Tax</span>
                            <span>{{ $quote->currency }} {{ number_format((float) $quote->tax_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="summary-row grand-total">
                        <span>Total Due</span>
                        <span>{{ $quote->currency }} {{ number_format((float) $quote->total_amount, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Terms & Notes --}}
            @if ($quote->terms || $quote->notes)
                <div class="terms-card">
                    @if ($quote->terms)
                        <h3>Terms & Conditions</h3>
                        <p style="margin-bottom: 0.75rem;">{{ $quote->terms }}</p>
                    @endif
                    @if ($quote->notes)
                        <h3>Retrospective Notes</h3>
                        <p>{{ $quote->notes }}</p>
                    @endif
                </div>
            @endif

            {{-- Signature Acceptance Block --}}
            @if ($quote->status === \Odden\Sales\Enums\QuoteStatus::Accepted)
                <div class="signature-section signed">
                    <div class="sig-title" style="color: #15803d;">
                        &#10003; Agreement Fully Executed
                    </div>
                    <p class="sig-subtitle" style="color: #166534;">
                        Signed electronically by <strong>{{ $quote->signed_by_name }}</strong> ({{ $quote->signed_by_email }})
                        on {{ $quote->accepted_at?->format('F j, Y \a\t g:i A') }}.
                    </p>
                    <div style="font-size: 0.75rem; color: #166534;">
                        Legal Audit Token: <code>{{ $quote->public_token }}</code>
                    </div>
                </div>
            @elseif (! $quote->isAcceptable())
                <div class="signature-section" style="background: #fef2f2; border-color: #fca5a5;">
                    @if ($quote->status === \Odden\Sales\Enums\QuoteStatus::Declined)
                        <div class="sig-title" style="color: #b91c1c;">Proposal Declined</div>
                        <p class="sig-subtitle" style="color: #991b1b;">
                            This quote was declined and is no longer available for acceptance. Please reach out to your sales representative to request an updated proposal.
                        </p>
                    @else
                        <div class="sig-title" style="color: #b91c1c;">Proposal Expired</div>
                        <p class="sig-subtitle" style="color: #991b1b;">
                            @if ($quote->expires_at)
                                This quote expired on {{ $quote->expires_at->format('F j, Y') }} and is no longer available for acceptance.
                            @else
                                This quote has expired and is no longer available for acceptance.
                            @endif
                            Please reach out to your sales representative to request an updated proposal.
                        </p>
                    @endif
                </div>
            @else
                <div class="signature-section">
                    <div class="sig-title">Accept & Sign Proposal</div>
                    <p class="sig-subtitle">Please enter your legal name and email to execute this agreement.</p>

                    <form action="{{ route('odden.quotes.accept', ['token' => $quote->public_token]) }}" method="POST">
                        @csrf
                        <div class="sig-form-grid">
                            <div class="sig-input-group">
                                <label for="signed_name">Full Legal Name</label>
                                <input
                                    type="text"
                                    id="signed_name"
                                    name="signed_name"
                                    class="sig-input"
                                    value="{{ old('signed_name', $contact?->full_name) }}"
                                    required
                                    oninput="document.getElementById('sig-preview').textContent = this.value || 'Your Signature';"
                                >
                            </div>

                            <div class="sig-input-group">
                                <label for="signed_email">Work Email Address</label>
                                <input
                                    type="email"
                                    id="signed_email"
                                    name="signed_email"
                                    class="sig-input"
                                    value="{{ old('signed_email', $contact?->email) }}"
                                    required
                                >
                            </div>

                            <div class="sig-preview-box" id="sig-preview">
                                {{ old('signed_name', $contact?->full_name ?? 'Your Signature') }}
                            </div>

                            <div style="grid-column: span 2; display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                                <input type="checkbox" id="agree_terms" name="agree_terms" required>
                                <label for="agree_terms" style="font-size: 0.8125rem; color: #475569;">
                                    I agree to the quote terms and confirm I am authorized to sign this agreement.
                                </label>
                            </div>

                            <div style="grid-column: span 2; margin-top: 0.75rem;">
                                <button type="submit" class="btn-accept">
                                    Accept & Sign Proposal &rarr;
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
