<?php

// SSO token-handoff dari J-ERP (FO). Secret HARUS sama dgn J-ERP (config ipro.sso_secret).
return [
    'secret' => env('SSO_SHARED_SECRET'),

    // URL aplikasi FO (J-ERP) untuk link silang "Kembali ke Dashboard". Set per server
    // (mis. https://fo.test.com). Dibaca runtime via Inertia → tak perlu rebuild aset.
    'fo_url' => rtrim(env('FO_URL', 'http://localhost:8081'), '/'),

    // Peta email FO (J-ERP) → BO (ipro-fo) bila berbeda. IDEALNYA email user disamakan
    // di kedua app (atau pakai 1 tabel users saat fase 1-aplikasi). Sementara, petakan
    // admin agar SSO bisa langsung diuji. Tambah baris utk user lain sesuai kebutuhan.
    'email_map' => [
        'admin@jerp.test' => 'admin@mup.local',
    ],
];
