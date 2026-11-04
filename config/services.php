<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
    | Texts to employees about their shifts and to customers when an order is
    | ready (App\Notifications\Channels\SmsChannel). With no key the texts are
    | written to the log instead of sent.
    */
    'unisms' => [
        'key' => env('UNISMS_SECRET_KEY'),
        // Blank falls back to the gateway's default sender until a custom one
        // is approved by the telcos.
        'sender_id' => env('UNISMS_SENDER_ID') ?: 'UniSMS',
        'url' => env('UNISMS_URL', 'https://unismsapi.com/api/sms'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
