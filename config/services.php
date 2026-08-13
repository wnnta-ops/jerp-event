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

    // Asisten AI FO — pilih provider: 'auto' (Claude bila ada key, else Gemini,
    // else fallback), atau paksa 'claude' / 'gemini'.
    'ai' => [
        'provider' => env('AI_PROVIDER', 'auto'),
    ],

    // Anthropic Claude — Asisten AI FO (cari data + jawab pertanyaan).
    // Tanpa api_key → jatuh ke NullAiAssistantGateway (mode kata kunci sederhana).
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
    ],

    // Google Gemini — alternatif provider Asisten AI (function-calling).
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 1024),
    ],

    // Mekari Qontak — WhatsApp notification (UTJ payment & NUP confirmation)
    'qontak' => [
        'access_token' => env('QONTAK_ACCESS_TOKEN'),
        'channel_integration_id' => env('QONTAK_CHANNEL_INTEGRATION_ID'),
        'base_url' => env('QONTAK_BASE_URL', 'https://service-chat.qontak.com/api/open/v1'),
        'language' => env('QONTAK_LANGUAGE', 'id'),
        'template_utj' => env('QONTAK_TEMPLATE_UTJ'),               // template tagihan UTJ
        'template_konfirmasi' => env('QONTAK_TEMPLATE_NUP_APPROVAL'), // template konfirmasi No NUP terbit (setelah approval)
        // Template "pembayaran UTJ diterima" + LAMPIRAN kwitansi (Header: Document).
        // 3 variabel: {{1}} nama, {{2}} nominal (tanpa "Rp"), {{3}} nama acara.
        // Kosong → WA dilewati diam-diam (email tetap terkirim), dicatat di log.
        'template_utj_paid' => env('QONTAK_TEMPLATE_UTJ_PAID'),
    ],

];
