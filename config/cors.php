<?php

/*
 * CORS untuk Mobile Approver API (aplikasi Expo/React Native).
 * Dev: izinkan semua origin pada path api/* (token Bearer, bukan cookie).
 * Produksi: persempit allowed_origins ke domain app bila perlu.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer token (bukan cookie) → tidak perlu credentials.
    'supports_credentials' => false,
];
