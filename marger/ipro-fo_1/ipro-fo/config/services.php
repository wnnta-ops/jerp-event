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
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
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
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
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
        'token' => env('WA_BUSINESS_API_TOKEN'),
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

    /*
    |--------------------------------------------------------------------------
    | MUK ERP — API kontrak & progres konstruksi (baca-saja)
    |--------------------------------------------------------------------------
    | Sumber angka SPK: kontrak owner, progres per lokasi, dan COP Owner dipegang
    | aplikasi MUK. BO menariknya supaya angka tagihan tidak diketik ulang.
    |
    | Kuncinya HANYA dari .env. Jangan pernah ditulis di sini: berkas ini masuk git,
    | dan dokumen MUK sendiri melarang menaruh kunci di repositori.
    |
    | 'aktif' sengaja ada: bila API mereka bermasalah, integrasi bisa dimatikan tanpa
    | menurunkan versi — layar tetap memakai data hasil tarikan terakhir.
    */
    /*
    |--------------------------------------------------------------------------
    | Sistem Komplain / Garansi — API unit & komplain (baca-saja)
    |--------------------------------------------------------------------------
    | Sumber TANGGAL BAST: serah terima unit dicatat di sistem Komplain, bukan di BO.
    | Dari 1464 unit di sana, 1110 punya bast_date — sementara BO hanya punya 18 dokumen.
    |
    | Kuncinya HANYA dari .env; dokumen mereka melarang menaruhnya di repositori, dan
    | data yang dibawa memuat PII (nama pemilik, no. HP) serta access_code APP Warga.
    */
    /*
    |--------------------------------------------------------------------------
    | Jadwal harian BO lama (hold/booking expire, denda, PJT, tunggakan)
    |--------------------------------------------------------------------------
    | Kelimanya TIDAK PERNAH berjalan: kontainer ipro-fo-scheduler tak pernah dinyalakan.
    | Karena penjadwal kini hidup untuk penarikan MUK & Komplain, kelimanya digerbangi
    | agar tidak ikut menyala diam-diam dan memproses tumpukan berbulan-bulan sekaligus.
    |
    | Dibaca lewat config, BUKAN env() langsung di routes/console.php: saat config:cache
    | dipakai — dan up-bo.sh memakainya — Laravel tidak lagi memuat .env, sehingga env()
    | selalu memulangkan nilai bawaan dan sakelarnya tak pernah bisa dinyalakan.
    */
    'jadwal_harian_bo' => env('BO_JADWAL_HARIAN_AKTIF', false),

    'komplain' => [
        'url' => env('CM_API_URL', 'https://erpmuk.java-sistem.com/api/integration'),
        'key' => env('CM_API_KEY'),
        'aktif' => env('CM_API_AKTIF', true),
        'timeout' => (int) env('CM_API_TIMEOUT', 20),
    ],

    'muk' => [
        'url' => env('MUK_API_URL', 'https://app.mukjavacoresystem.com/api/mup'),
        'key' => env('MUK_API_KEY'),
        'aktif' => env('MUK_API_AKTIF', true),
        'timeout' => (int) env('MUK_API_TIMEOUT', 20),
    ],

];
