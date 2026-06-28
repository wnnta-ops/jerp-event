<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Xendit Payment Gateway
    |--------------------------------------------------------------------------
    | Diisi via .env. Selama XENDIT_SECRET_KEY kosong, sistem berjalan dalam
    | mode SIMULASI (pembayaran bisa diuji tanpa key asli).
    */

    'xendit' => [
        'secret_key'    => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'base_url'      => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        // TTL invoice (detik). Default 24 jam.
        'invoice_duration' => (int) env('XENDIT_INVOICE_DURATION', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Cloud API / Business)
    |--------------------------------------------------------------------------
    | Selama token/phone_id kosong, sistem berjalan mode SIMULASI: pesan dicatat
    | ke log dan disediakan tautan wa.me agar bisa dikirim manual.
    | Default format: Meta WhatsApp Business Cloud API.
    */
    'whatsapp' => [
        'base_url' => env('WA_BUSINESS_API_URL', 'https://graph.facebook.com/v19.0'),
        'token'    => env('WA_BUSINESS_API_TOKEN'),
        'phone_id' => env('WA_BUSINESS_PHONE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tesseract OCR (lokal, offline) — untuk baca KTP/NPWP di Inisiasi KPR
    |--------------------------------------------------------------------------
    | Path absolut ke tesseract.exe. Dibaca via config (aman thd config:cache).
    | Kosongkan → service akan auto-deteksi lokasi umum.
    */
    'tesseract' => [
        'bin' => env('OCR_TESSERACT_BIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PaddleOCR microservice (lokal, offline) — baca KTP/NPWP lebih akurat
    |--------------------------------------------------------------------------
    | Microservice Python (FastAPI + PaddleOCR) berjalan di 127.0.0.1:8001.
    | IdDocumentOcr memanggilnya lebih dulu; bila mati → fallback ke Tesseract.
    | PII tidak keluar server (service berjalan lokal).
    */
    'paddleocr' => [
        'url' => env('OCR_PADDLE_URL', 'http://127.0.0.1:8001'),
    ],

];
