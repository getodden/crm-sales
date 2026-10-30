<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $link->title }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    @if ($recipientRole === 'host')
        <p>{{ $booking->invitee_name }} ({{ $booking->invitee_email }}) booked <strong>{{ $link->title }}</strong> with you.</p>
    @else
        <p>Hi {{ $booking->invitee_name }},</p>
        <p>Your meeting <strong>{{ $link->title }}</strong> with {{ $hostName }} is confirmed.</p>
    @endif

    <p>
        <strong>{{ $startsAt->format('l, F j, Y') }}</strong><br>
        {{ $startsAt->format('g:i A') }} &ndash; {{ $endsAt->format('g:i A') }} ({{ $booking->timezone }})
    </p>

    @if ($link->description)
        <p>{{ $link->description }}</p>
    @endif

    <p>The attached calendar invite (invite.ics) adds the meeting to your calendar.</p>

    @if ($cancelUrl)
        <p>Can't make it? <a href="{{ $cancelUrl }}">Cancel this meeting</a>.</p>
    @endif
</body>
</html>
