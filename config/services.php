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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'recaptcha' => [
        'enabled' => (bool) env('RECAPTCHA_ENABLED', false),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret' => env('RECAPTCHA_SECRET_KEY'),
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
    ],

    // Mekari Qontak — WhatsApp notification (UTJ payment & NUP confirmation)
    'qontak' => [
        'access_token' => env('QONTAK_ACCESS_TOKEN'),
        'channel_integration_id' => env('QONTAK_CHANNEL_INTEGRATION_ID'),
        'base_url' => env('QONTAK_BASE_URL', 'https://service-chat.qontak.com/api/open/v1'),
        'language' => env('QONTAK_LANGUAGE', 'id'),
        'template_utj' => env('QONTAK_TEMPLATE_UTJ'),               // template tagihan UTJ
        'template_konfirmasi' => env('QONTAK_TEMPLATE_KONFIRMASI'), // template konfirmasi No NUP terbit
    ],

];
