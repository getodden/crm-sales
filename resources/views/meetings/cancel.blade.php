<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Cancel {{ $link->title }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            line-height: 1.5;
            padding: 2.5rem 1rem;
        }

        .card {
            max-width: 480px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 0.75rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 2rem;
        }

        h1 { font-size: 1.375rem; font-weight: 800; letter-spacing: -0.025em; margin-bottom: 0.25rem; }
        .meta { color: #475569; font-size: 0.9375rem; margin-top: 0.75rem; }
        label { display: block; font-size: 0.875rem; font-weight: 600; margin: 1.5rem 0 0.375rem; }

        textarea {
            width: 100%;
            padding: 0.625rem 0.75rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font: inherit;
            font-size: 0.875rem;
        }

        .btn-cancel {
            margin-top: 1rem;
            width: 100%;
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 0.5rem;
            background: #b91c1c;
            color: #ffffff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .notice { background: #f1f5f9; border-radius: 0.5rem; padding: 1rem; margin-top: 1.25rem; font-size: 0.9375rem; }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 1rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; margin-bottom: 1.25rem; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; padding: 1rem 1.25rem; border-radius: 0.5rem; font-size: 0.875rem; margin-bottom: 1.25rem; }
    </style>
    @include('odden-sales::partials.branding')
</head>
<body>
    <div class="card">
        @include('odden-sales::partials.logo')

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <h1>{{ $link->title }}</h1>
        <p class="meta">With {{ $link->user->name }}</p>
        <p class="meta">
            <strong>{{ $startsAt->format('l, F j, Y') }}</strong><br>
            {{ $startsAt->format('g:i A') }} &ndash; {{ $endsAt->format('g:i A') }} ({{ $booking->timezone }})
        </p>

        @if ($state === 'cancelled')
            <div class="notice">This meeting has been cancelled. Nothing more to do.</div>
        @elseif ($state === 'past')
            <div class="notice">This meeting has already started or taken place, so it can no longer be cancelled here. Please contact {{ $link->user->name }} directly.</div>
        @else
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <label for="reason">Reason (optional)</label>
                <textarea id="reason" name="reason" rows="3" maxlength="1000">{{ old('reason') }}</textarea>
                <button type="submit" class="btn-cancel">Cancel this meeting</button>
            </form>
        @endif
    </div>
</body>
</html>
