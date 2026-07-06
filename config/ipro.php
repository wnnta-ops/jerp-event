<?php

// Konfigurasi integrasi J-ERP (FO) → ipro-fo (BO/Bankloan), database ipro_bo.
// Posting GL & sync master ditulis langsung ke ipro_bo (1 MySQL).
return [
    // Koneksi DB ke ipro_bo (lihat config/database.php → connections.ipro_bo).
    'connection' => env('IPRO_DB_CONNECTION', 'ipro_bo'),

    // APP_KEY ipro-fo — WAJIB sama dgn ipro-fo agar field terenkripsi (NIK/NPWP via
    // Crypt::encryptString, AES-256-CBC) bisa didekripsi ipro-fo. Ambil dari .env
    // ipro-fo (jangan jalankan key:generate ipro-fo lagi setelah ini diisi).
    'app_key' => env('IPRO_APP_KEY'),

    // Pemetaan tenant & project default ipro_bo (tenant code MUP=1, project JRES=1).
    'tenant_id'  => (int) env('IPRO_TENANT_ID', 1),
    'project_id' => (int) env('IPRO_PROJECT_ID', 1),

    // Sync master otomatis (event saat FO disimpan). Set false utk matikan.
    'sync_enabled' => (bool) env('IPRO_SYNC_ENABLED', true),

    // Provisioning pengguna FO → ipro_bo.users (BY EMAIL) agar SSO FO→BO cocok.
    // Saat user FO dibuat/identitasnya berubah, kembaran user BO dibuat/diselaraskan.
    'user_sync_enabled' => (bool) env('IPRO_USER_SYNC_ENABLED', true),
    // Grup akses BO default untuk user hasil provisioning (kosong = tanpa role).
    'default_bo_role' => env('IPRO_DEFAULT_BO_ROLE', 'Viewer'),

    // Prefix kode akun BANK di Chart of Account BO (bs_accounts.code) — untuk
    // dropdown "Bank Penerima" Transfer Anonim (akun bank postable di bawah grup ini).
    'coa_bank_prefix' => env('IPRO_COA_BANK_PREFIX', '10101020'),

    // Pemetaan role FO (jerp) → role BO (ipro_bo). Hak akses BO MENGIKUTI FO:
    // saat user FO disinkron, grup akses BO-nya diselaraskan dari role FO di sini.
    // Role FO tak terpetakan → default_bo_role. Role BO-only (mis. Agent) yang
    // di-set manual di "Pengguna BO" dipertahankan (tak ditimpa). Silakan sesuaikan.
    'role_map' => [
        'super_admin'     => 'SuperAdmin',
        'it_admin'        => 'Admin',
        'direktur'        => 'Direktur',
        'finance_manager' => 'FinanceManager',
        'finance_staff'   => 'Finance',
        'accounting'      => 'Finance',
        'kasir_launching' => 'Finance',
        'kpr_officer'     => 'Finance',   // best-effort: BO tak punya role bankloan khusus
        'collector'       => 'Collection',
        'sales_manager'   => 'SalesManager',
        'sales_staff'     => 'Sales',
        'auditor'         => 'Viewer',
        'legal_staff'     => 'Viewer',    // best-effort: BO tak punya role legal khusus
    ],

    // ── SSO token-handoff ke BO ipro-fo ────────────────────────────────────────
    'sso_secret' => env('SSO_SHARED_SECRET'),
    'bo_url'     => rtrim(env('IPRO_BO_URL', 'http://localhost:8090'), '/'),

    // ── Posting GL FO → ipro_bo ────────────────────────────────────────────────
    'gl_enabled' => (bool) env('IPRO_GL_ENABLED', true),
    // Status jurnal hasil posting FO. DRAFT = belum final → akuntan verifikasi dulu
    // mapping COA di bawah sebelum diubah ke 'posted'.
    'gl_status'  => env('IPRO_GL_STATUS', 'draft'),

    // Cipher enkripsi PII (ikut ipro-fo). Default AES-256-CBC.
    'cipher' => env('IPRO_CIPHER', 'AES-256-CBC'),

    // Akun "suspense" (bs_accounts.id) untuk menampung posting saat COA tak terpetakan,
    // agar kas tak hilang. Kosong (0/null) = posting GL gagal → masuk outbox fo_sync_failures.
    'suspense_account_id' => (int) env('IPRO_SUSPENSE_ACCOUNT_ID', 0),

    // Pemetaan kode COA J-ERP (config/fobo.php) → id bs_accounts ipro_bo.
    // ⚠️ WAJIB DIVERIFIKASI AKUNTAN. Yang pasti: Kas, Piutang, Penjualan, Komisi.
    // Yang perlu dicek (best-effort): utang komisi, escrow akad, beban memo/KPR.
    'coa_map' => [
        '1.1.01.002' => 6,    // Kas/Bank          → KAS OPERASIONAL (10101010101)
        '1.1.02.001' => 31,   // Piutang usaha     → PIUTANG USAHA (10102010101)
        '4.1.01.001' => 157,  // Pendapatan jual   → PENJUALAN USAHA (40101010101)
        '5.2.01.001' => 183,  // Beban komisi      → KOMISI/INSENTIF PENJUALAN (50201010103)
        '2.1.01.001' => 130,  // Utang komisi      → UANG MUKA PENJUALAN (cek akuntan!)
        '1.1.01.104' => 6,    // Escrow/Bank akad  → KAS OPERASIONAL (cek akuntan!)
        '2.1.01.004' => 130,  // Titipan/refund    → UANG MUKA PENJUALAN (20106060601)
        '5.9.01.001' => 181,  // Beban memo        → BEBAN PROMOSI PENJUALAN (cek akuntan!)
        '5.9.01.002' => 181,  // Beban proses KPR  → BEBAN PROMOSI PENJUALAN (cek akuntan!)
    ],
];
