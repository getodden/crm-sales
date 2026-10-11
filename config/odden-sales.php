<?php

declare(strict_types=1);

return [
    'tables' => [
        'pipelines' => 'odden_pipelines',
        'stages' => 'odden_pipeline_stages',
        'deals' => 'odden_deals',
        'stage_history' => 'odden_deal_stage_history',
        'products' => 'odden_deal_products',
        'quotes' => 'odden_quotes',
        'quote_items' => 'odden_quote_items',
        'automations' => 'odden_stage_automations',
        'quotas' => 'odden_sales_quotas',
        'email_templates' => 'odden_sales_email_templates',
        'sequences' => 'odden_sales_sequences',
        'sequence_enrollments' => 'odden_sales_sequence_enrollments',
        'playbooks' => 'odden_sales_playbooks',
        'meeting_links' => 'odden_sales_meeting_links',
        'meeting_bookings' => 'odden_sales_meeting_bookings',
        'lead_routing_rules' => 'odden_sales_lead_routing_rules',
    ],

    'default_currency' => env('ODDEN_DEFAULT_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Every email Sales sends (sequence email steps and meeting confirmations)
    | is a queued mailable, so run a queue worker. Leave a value null to use
    | the app's default mailer, queue connection, queue, or "from" address.
    | With sequences.send_as_owner, sequence emails are sent from the rep who
    | enrolled the contact (or the sequence author); otherwise the rep is
    | only used as the reply-to address.
    |
    */
    'mail' => [
        'mailer' => env('ODDEN_SALES_MAILER'),
        'connection' => env('ODDEN_SALES_QUEUE_CONNECTION'),
        'queue' => env('ODDEN_SALES_MAIL_QUEUE'),

        'from' => [
            'address' => env('ODDEN_SALES_FROM_ADDRESS'),
            'name' => env('ODDEN_SALES_FROM_NAME'),
        ],

        'sequences' => [
            'send_as_owner' => (bool) env('ODDEN_SALES_SEND_AS_OWNER', false),

            // Where replies to sequence emails go. Null uses the rep's own address. Set it to an address your app
            // receives mail at, so a reply can be seen (and StopSequencesOnReplyAction can stop the sequence).
            'reply_to' => env('ODDEN_SALES_REPLY_TO'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meeting links
    |--------------------------------------------------------------------------
    |
    | default_working_hours applies to meeting links whose working_hours is
    | empty. Keys are lowercase English day names; each value is a list of
    | "HH:MM-HH:MM" windows in the link's timezone. booking_window_days is
    | how far ahead visitors can book.
    |
    */
    'meetings' => [
        'default_working_hours' => [
            'monday' => ['09:00-17:00'],
            'tuesday' => ['09:00-17:00'],
            'wednesday' => ['09:00-17:00'],
            'thursday' => ['09:00-17:00'],
            'friday' => ['09:00-17:00'],
        ],

        'booking_window_days' => (int) env('ODDEN_SALES_BOOKING_WINDOW_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The public quote e-sign portal and meeting scheduler. The group accepts
    | a domain, prefix and middleware. Set "enabled" to false to register your
    | own routes instead; keep the odden.quotes.* and odden.meetings.* route
    | names, since quotes and meeting links are generated from them.
    |
    */
    'routes' => [
        'enabled' => (bool) env('ODDEN_SALES_ROUTES_ENABLED', true),

        'web' => [
            'domain' => env('ODDEN_SALES_DOMAIN'),
            'prefix' => env('ODDEN_SALES_PREFIX', ''),
            'middleware' => ['web'],
        ],
    ],
];
