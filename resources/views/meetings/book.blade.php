<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $link->title }} - {{ $link->user->name }}</title>
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
            padding: 2.5rem 1rem;
        }

        .booking-card {
            max-width: 780px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 0.75rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: grid;
            grid-template-columns: 1fr;
            overflow: hidden;
        }

        @media (min-width: 768px) {
            .booking-card {
                grid-template-columns: 1fr 1.4fr;
            }
        }

        .rep-info-col {
            padding: 2.25rem;
            background: #fafaf9;
            border-bottom: 1px solid #e2e8f0;
        }

        @media (min-width: 768px) {
            .rep-info-col {
                border-bottom: none;
                border-right: 1px solid #e2e8f0;
            }
        }

        .rep-avatar {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 9999px;
            background: var(--odden-accent, #0284c7);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
        }

        .rep-name {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #64748b;
        }

        .meeting-title {
            font-size: 1.375rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 0.25rem;
            letter-spacing: -0.025em;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #475569;
            margin-top: 1rem;
        }

        .meeting-desc {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 1rem;
            line-height: 1.6;
        }

        .form-col {
            padding: 2.25rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .form-group label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #334155;
        }

        .form-input {
            padding: 0.625rem 0.875rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .form-input:focus {
            border-color: var(--odden-accent, #0284c7);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
        }

        .slot-radio {
            display: none;
        }

        .slot-btn {
            display: block;
            text-align: center;
            padding: 0.5rem 0.25rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--odden-accent, #0284c7);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .slot-radio:checked + .slot-btn {
            background: var(--odden-accent, #0284c7);
            color: #ffffff;
            border-color: var(--odden-accent, #0284c7);
        }

        .btn-book {
            padding: 0.75rem 1.25rem;
            border-radius: 0.375rem;
            background: var(--odden-accent, #0d9488);
            color: #ffffff;
            font-size: 0.9375rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: background 0.15s ease;
            width: 100%;
            margin-top: 0.5rem;
        }

        .btn-book:hover {
            background: var(--odden-accent-hover, #0f766e);
        }

        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 1rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }

        .date-row {
            display: flex;
            gap: 0.5rem;
        }

        .date-row .form-input {
            flex: 1;
        }

        .btn-secondary {
            padding: 0.625rem 0.875rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.375rem;
            background: #ffffff;
            color: #334155;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
        }

        .tz-note {
            font-weight: 400;
            color: #64748b;
        }

        .no-slots {
            font-size: 0.875rem;
            color: #64748b;
            padding: 0.75rem;
            border: 1px dashed #cbd5e1;
            border-radius: 0.375rem;
        }

        .btn-book:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
            padding: 1rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
        }
    </style>
    @include('odden-sales::partials.branding')
</head>
<body>
    <div class="booking-card">
        {{-- Rep & Meeting Info --}}
        <div class="rep-info-col">
            @include('odden-sales::partials.logo')
            <div class="rep-avatar">
                {{ strtoupper(substr($link->user->name, 0, 2)) }}
            </div>
            <div class="rep-name">{{ $link->user->name }}</div>
            <h1 class="meeting-title">{{ $link->title }}</h1>

            <div class="meta-item">
                <span>&#9201;</span>
                <span>{{ $link->duration_minutes }} minutes</span>
            </div>
            <div class="meta-item">
                <span>&#128197;</span>
                <span>A confirmation email with a calendar invite follows your booking</span>
            </div>

            @if ($link->description)
                <div class="meeting-desc">
                    {{ $link->description }}
                </div>
            @endif
        </div>

        {{-- Booking Form --}}
        <div class="form-col">
            @if (session('status'))
                <div class="alert-success">
                    &#10003; {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert-error">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form action="{{ route('odden.meetings.show', ['slug' => $link->slug]) }}" method="GET" class="form-group">
                <label for="date">Select Date</label>
                <div class="date-row">
                    <input
                        type="date"
                        id="date"
                        name="date"
                        class="form-input"
                        value="{{ $date }}"
                        min="{{ $minDate }}"
                        max="{{ $maxDate }}"
                        onchange="this.form.submit()"
                        required
                    >
                    <button type="submit" class="btn-secondary">Show times</button>
                </div>
            </form>

            <form action="{{ route('odden.meetings.book', ['slug' => $link->slug]) }}" method="POST">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div class="form-group">
                        <label>Select Time Slot <span class="tz-note">({{ $timezone }})</span></label>
                        @if (count($slots) === 0)
                            <div class="no-slots">No times are available on this date. Please pick another date.</div>
                        @else
                            <div class="time-slots-grid">
                                @foreach ($slots as $slot)
                                    <label>
                                        <input
                                            type="radio"
                                            name="time"
                                            value="{{ $slot->format('H:i') }}"
                                            class="slot-radio"
                                            {{ old('time') === $slot->format('H:i') || (old('time') === null && $loop->first) ? 'checked' : '' }}
                                            required
                                        >
                                        <span class="slot-btn">{{ $slot->format('g:i A') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="form-group">
                        <label for="name">Your Name</label>
                        <input type="text" id="name" name="name" class="form-input" value="{{ old('name') }}" placeholder="Jane Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Work Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" value="{{ old('email') }}" placeholder="jane@company.com" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number (optional)</label>
                        <input type="tel" id="phone" name="phone" class="form-input" value="{{ old('phone') }}" placeholder="+1 (555) 012-3456">
                    </div>

                    <div class="form-group">
                        <label for="notes">Meeting Objective / Notes (optional)</label>
                        <textarea id="notes" name="notes" rows="2" class="form-input" placeholder="What would you like to cover?">{{ old('notes') }}</textarea>
                    </div>

                    <button type="submit" class="btn-book" @disabled(count($slots) === 0)>
                        Confirm Meeting &rarr;
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
