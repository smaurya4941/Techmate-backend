<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Google Places API (New): powers GET /api/v1/google-reviews. The key is
    | server-side only; leave it empty and the endpoint answers 503 "not_configured".
    */
    'google_places' => [
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
        'place_id' => env('GOOGLE_PLACES_PLACE_ID'),
        // Public listing URL used as a fallback when Google does not return googleMapsUri.
        'maps_url' => env('GOOGLE_MAPS_REVIEWS_URL'),
        'language' => env('GOOGLE_PLACES_LANGUAGE', 'en'),
        'cache_seconds' => (int) env('GOOGLE_REVIEWS_CACHE_SECONDS', 3600),
        'timeout_seconds' => (int) env('GOOGLE_PLACES_TIMEOUT_SECONDS', 5),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
