<?php

/*
 * Notifikasi push (Web Push) app approver PWA. Kunci VAPID dibuat sekali per lingkungan:
 *   php artisan pwa:vapid        (menulis VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY ke .env)
 * Mengganti kunci membuat semua langganan lama tidak berlaku — HP harus menyalakan ulang.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'mailto:admin@jerp.test')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // APK Android (Flutter) lewat Firebase Cloud Messaging: file service account Firebase (path),
    // atau isinya dalam base64 (praktis untuk .env server). Bukan rahasia yang boleh masuk Git.
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
        'credentials_b64' => env('FCM_CREDENTIALS_B64'),
    ],

    // Alamat PWA yang dibuka saat notifikasi diketuk (relatif ke scope service worker).
    'buka_url' => env('PWA_PUSH_BUKA_URL', './'),

    // Jam pengingat harian bawaan (bisa diubah per perangkat di Pengaturan).
    'jam_harian' => '08:00',
];
