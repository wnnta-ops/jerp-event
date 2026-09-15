<?php

declare(strict_types=1);

return [
    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    'register_permission_check_method' => true,
    'register_octane_reset_listener' => true,
    'events_enabled' => true,
    'teams' => false,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => true,
    'display_role_in_exception' => true,
    'enable_wildcard_permission' => false,

    'cache' => [
        /*
         * 60 detik, bukan 24 jam bawaan.
         *
         * Grup Akses BO dikelola dari panel System J-ERP, yang menulis LANGSUNG ke tabel
         * spatie di database ipro_bo — menembus cache ini. Dengan 24 jam, perubahan hak
         * akses seseorang bisa tidak berlaku sepanjang hari kerja, dan satu-satunya jalan
         * mempercepatnya adalah menjalankan `permission:cache-reset` di server.
         *
         * Membatalkan cache dari sisi J-ERP tidak bisa: kedua aplikasi TIDAK punya jalur
         * langsung. Redis-nya dua instance terpisah (jerp-redis & ipro-fo-redis, kebetulan
         * sama-sama bernama host "redis"), dan jaringannya terpisah sehingga jerp-app tidak
         * bisa menghubungi BO lewat HTTP. Yang dibagi hanya MySQL.
         *
         * Ongkosnya diukur di server ini: membangun ulang 49 ms (tengah dari 15 kali,
         * terburuk 90 ms) untuk 430 izin, sementara membaca cache hangat 0,02 ms. Yang
         * berubah cuma SATU permintaan per menit yang membayar 49 ms — dulu satu per hari.
         * Sisanya tetap 0,02 ms. Bila BO tidak dipakai, tidak ada yang berjalan.
         *
         * Biayanya tumbuh sebanding jumlah izin. Bila kelak jauh lebih banyak dan terasa,
         * gantinya adalah memindahkan cache ini ke tabel di ipro_bo supaya J-ERP bisa
         * menghapus barisnya sendiri — seketika, tapi perlu tabel baru dan mengikat J-ERP
         * pada cache internal BO. Belum sepadan sekarang.
         */
        'expiration_time' => DateInterval::createFromDateString('60 seconds'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
