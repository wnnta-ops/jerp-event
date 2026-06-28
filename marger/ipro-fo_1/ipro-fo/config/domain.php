<?php

declare(strict_types=1);

/**
 * Konfigurasi parameter bisnis Java Core System.
 * Override via .env. Bisa juga override per-tenant via tabel `parameters`.
 */

return [
    'tax' => [
        'ppn_default_pct' => (float) env('DOMAIN_DEFAULT_PPN_PCT', 11.0),
    ],
    'currency' => env('DOMAIN_DEFAULT_CURRENCY', 'IDR'),
    'timezone' => env('DOMAIN_TIMEZONE', 'Asia/Jakarta'),

    'denda' => [
        'rate_per_day' => (float) env('DOMAIN_DEFAULT_DENDA_RATE_PER_DAY', 0.0005), // 0.05% / hari
        'grace_days'   => (int)   env('DOMAIN_DENDA_GRACE_DAYS', 0),
    ],

    'hold' => [
        'default_days' => (int) env('DOMAIN_DEFAULT_HOLD_DAYS', 7),
        'max_days'     => (int) env('DOMAIN_MAX_HOLD_DAYS', 14),
    ],

    'nup' => [
        'refund_penalty_pct' => (float) env('DOMAIN_DEFAULT_NUP_REFUND_PENALTY_PCT', 10.0),
    ],

    'auth' => [
        'max_failed_attempts'      => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),
        'lockout_minutes'          => (int) env('AUTH_LOCKOUT_MINUTES', 15),
        'password_rotation_days'   => (int) env('AUTH_PASSWORD_ROTATION_DAYS', 90),
        'session_idle_minutes'     => (int) env('AUTH_SESSION_IDLE_MINUTES', 30),
        'mfa_required_roles'       => array_filter(explode(',', (string) env('AUTH_MFA_REQUIRED_ROLES', ''))),
        'password_history_count'   => 5,
        'password_min_length'      => 12,
    ],

    'discount' => [
        // Threshold approval — jika diskon > tier, auto-route ke approver tier yang sesuai.
        'thresholds' => [
            'tier_1' => ['min' => 0,  'max' => 3,  'role' => null],            // 0-3% auto-approve
            'tier_2' => ['min' => 3,  'max' => 7,  'role' => 'SalesManager'],
            'tier_3' => ['min' => 7,  'max' => 12, 'role' => 'SalesDirector'],
            'tier_4' => ['min' => 12, 'max' => 100,'role' => 'Direktur'],
        ],
    ],

    'numerator' => [
        // Format kalau tidak ada di DB, fallback default.
        'fallback_format' => '{PREFIX}/{YY}{MM}/{SEQ}',
    ],

    'pdf' => [
        'browsershot' => [
            'chromium_path' => env('CHROMIUM_PATH', '/usr/bin/chromium-browser'),
        ],
    ],

    'webhook' => [
        'bank_va_secret' => env('WEBHOOK_BANK_VA_SECRET'),
        'privyid_secret' => env('WEBHOOK_PRIVYID_SECRET'),
        'wa_token'       => env('WEBHOOK_WHATSAPP_TOKEN'),
    ],
];
