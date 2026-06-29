<?php

// SSO token-handoff dari J-ERP (FO). Secret HARUS sama dgn J-ERP (config ipro.sso_secret).
return [
    'secret' => env('SSO_SHARED_SECRET'),

    // URL aplikasi FO (J-ERP) untuk link silang "Kembali ke Dashboard". Set per server
    // (mis. https://fo.test.com). Dibaca runtime via Inertia → tak perlu rebuild aset.
    'fo_url' => rtrim(env('FO_URL', 'http://localhost:8081'), '/'),

    // Akses BO/Bankloan HANYA via login J-ERP (1 pintu). true = halaman /login ipro-fo
    // dialihkan ke FO_URL/login; masuk BO hanya lewat SSO dari hub J-ERP.
    // Set false HANYA utk pemulihan darurat (mis. SSO bermasalah) agar bisa login langsung.
    'fo_login_only' => (bool) env('SSO_FO_LOGIN_ONLY', true),

    // Peta email FO (J-ERP) → BO (ipro-fo) bila berbeda. IDEALNYA email user disamakan
    // di kedua app (atau pakai 1 tabel users saat fase 1-aplikasi). Sementara, petakan
    // admin agar SSO bisa langsung diuji. Tambah baris utk user lain sesuai kebutuhan.
    'email_map' => [
        'admin@jerp.test' => 'admin@mup.local',
    ],
];
