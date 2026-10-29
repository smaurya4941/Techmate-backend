<?php

return [

    /*
    | Internal inbox that receives new-lead / new-application notifications.
    | When null, internal notifications are skipped (acknowledgements still send).
    */
    'sales_notification_email' => env('TECHMATE_SALES_EMAIL'),

    /*
    | Public rate limits per client IP. Generous enough for several users behind
    | one office NAT; tune per deployment through the environment.
    */
    'rate_limits' => [
        'read_per_minute' => (int) env('API_READ_RATE_PER_MINUTE', 120),
        'submit_per_minute' => (int) env('API_SUBMIT_RATE_PER_MINUTE', 6),
        'submit_per_hour' => (int) env('API_SUBMIT_RATE_PER_HOUR', 30),
    ],

    'catalogue' => [
        'default_per_page' => 12,
        'max_per_page' => 100,
    ],

    'quote_request' => [
        'max_items' => 20,
        'max_quantity' => 1000,
    ],

    // Optional webhook that asks the Next.js frontend to drop cached public pages
    // after admin changes. Leave empty to rely on the frontend's 60s revalidation.
    'frontend' => [
        'revalidate_url' => env('FRONTEND_REVALIDATE_URL'),
        'revalidate_secret' => env('FRONTEND_REVALIDATE_SECRET'),
    ],

    'notifications' => [
        'queue' => env('TECHMATE_NOTIFICATION_QUEUE', 'default'),
    ],

    /*
    | Timestamps are stored in UTC. This is the business timezone used when dates are
    | shown to staff or customers and to decide what "today" means for follow-ups.
    */
    'business_timezone' => env('TECHMATE_TIMEZONE', 'Asia/Kolkata'),

    /*
    | Inbox for new-order notices to fulfilment. Falls back to the sales inbox.
    */
    'operations_notification_email' => env('TECHMATE_OPERATIONS_EMAIL'),

    'sales' => [
        // Quotation validity applied when the executive leaves "valid until" empty.
        'quotation_valid_days' => (int) env('TECHMATE_QUOTATION_VALID_DAYS', 30),
        // Default tax rate pre-filled on new quotation lines (percent). The accountant owns the real rule.
        'default_tax_rate' => env('TECHMATE_DEFAULT_TAX_RATE', '18'),
        'default_currency' => env('TECHMATE_DEFAULT_CURRENCY', 'INR'),
        // Heads-up reminder pre-filled on new follow-ups (minutes before due). Null = none.
        'default_reminder_minutes' => 30,
    ],
];
