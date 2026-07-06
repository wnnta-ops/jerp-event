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

    // Peta email FO (J-ERP) → BO (ipro-fo) bila berbeda. Kini email user FO & BO sudah
    // DISAMAKAN (provisioning otomatis membuat kembaran BO ber-email sama), jadi peta ini
    // kosong. Isi hanya bila ada user dengan email berbeda di kedua app.
    'email_map' => [
    ],
];
