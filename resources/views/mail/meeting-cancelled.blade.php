<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cancelled: {{ $link->title }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    @if ($recipientRole === 'host')
        <p>The meeting <strong>{{ $link->title }}</strong> with {{ $booking->invitee_name }} ({{ $booking->invitee_email }}) was cancelled by {{ $cancelledByName }}.</p>
    @else
        <p>Hi {{ $booking->invitee_name }},</p>
        <p>Your meeting <strong>{{ $link->title }}</strong> with {{ $hostName }} was cancelled by {{ $cancelledByName }}.</p>
    @endif

    <p>
        It was set for <strong>{{ $startsAt->format('l, F j, Y') }}</strong><br>
        {{ $startsAt->format('g:i A') }} &ndash; {{ $endsAt->format('g:i A') }} ({{ $booking->timezone }})
    </p>

    @if ($reason)
        <p>Reason: {{ $reason }}</p>
    @endif

    <p>The attached calendar update (cancelled.ics) removes the meeting from your calendar.</p>
</body>
</html>
