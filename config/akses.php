<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Sakelar penegakan
    |---------------------------------------------------------------------------
    | false  = MODE LAPOR. Semua pemeriksaan dijalankan dan yang gagal dicatat ke
    |          log, tetapi TIDAK ada yang ditolak. Dipakai untuk melihat siapa
    |          yang akan terkunci sebelum penegakan dinyalakan.
    | true   = MODE TEGAK. Pemeriksaan yang gagal berarti ditolak.
    |
    | Sengaja default false: sistem ini punya 61 akun dan 39 peran yang izinnya
    | belum dipetakan. Menyalakan penegakan sebelum pemetaan selesai akan
    | mengunci hampir semua orang pada hari kerja berikutnya.
    */
    'tegakkan' => (bool) env('AKSES_TEGAKKAN', false),

    /*
    | Peran yang SELALU lolos. Jaring pengaman supaya masih ada jalan masuk
    | kalau pemetaan izin ternyata keliru.
    */
    'peran_super' => ['super_admin'],

    /*
    | Izin yang dibutuhkan untuk membuka tiap panel. Sebelumnya panel hanya
    | memeriksa is_active — artinya setiap pengguna aktif bisa membuka SEMUA
    | panel, termasuk System dan Backup.
    */
    'panel' => [
        'fo' => 'panel.fo',
        'bo' => 'panel.bo',
        'system' => 'panel.system',
        'backup' => 'panel.backup',
    ],

    /*
    | Awalan izin per resource Filament, ditulis sebagai nama kelas pendek.
    | Resource yang TIDAK tercantum di sini tidak ditegakkan sama sekali (dan
    | dilaporkan `akses:audit` sebagai belum berpenjaga) — dibiarkan longgar
    | dengan sengaja supaya penambahan bertahap tidak mematikan menu yang
    | pemetaannya belum sempat ditinjau.
    */
    'resource' => [
        // FO — NUP & launching
        'NupRegistrationResource' => 'fo.nup',
        'VerifikasiNupResource' => 'fo.verifikasi_nup',
        'VerifikasiBookedResource' => 'fo.verifikasi_booked',
        'ReportSprBankResource' => 'fo.spr',
        'NupEventResource' => 'fo.nup',
        'NupTipeResource' => 'fo.nup',

        // FO — marketing & sales
        'CustomerResource' => 'fo.customer',
        'KontrakResource' => 'fo.kontrak',
        'ReservasiResource' => 'fo.reservasi',
        'UnitResource' => 'fo.customer',

        // FO — finance AR
        'TtsResource' => 'fo.tts',
        'BkmResource' => 'fo.bkm',
        'BgResource' => 'fo.bg',
        'MemoResource' => 'fo.memo',
        'FpResource' => 'fo.fp',
        'RefundResource' => 'fo.refund',
        'VaResource' => 'fo.va',
        'TransferAnonimResource' => 'fo.transfer_anonim',

        // System
        'UserResource' => 'system.user',
        'RoleResource' => 'system.role',

        // Backup
        'BackupScheduleResource' => 'backup.manage',
    ],

    /*
    | Rute ber-parameter yang SUDAH terjaga, tetapi penjaganya tidak berupa
    | middleware sehingga tak terlihat oleh `akses:audit`. Dicantumkan di sini
    | supaya laporan hanya menampilkan yang BENAR-BENAR baru — daftar yang isinya
    | alarm palsu akan berhenti dibaca orang.
    */
    'rute_dikecualikan' => [
        // Token HMAC di path, diperiksa hash_equals di controller.
        'fo.cetak.sprs-pdf',
        'fo.cetak.kwitansi-utj-pdf',
        // Memang publik: siteplan pemasaran & data wilayah untuk dropdown form.
        'siteplan.show',
        'siteplan.subdomain.show',
        'wilayah.regencies',
        'wilayah.districts',
        'wilayah.villages',
        // Laravel memeriksa tanda tangan di dalam handler (ReceiveFile), bukan middleware.
        'storage.local',
        'storage.local.upload',
    ],

    /*
    | Aksi Filament dipetakan ke akhiran izin. Kosakata aksi diambil apa adanya
    | dari 373 izin yang sudah terdaftar — bukan kosakata baru.
    */
    'aksi' => [
        'viewAny' => 'index',
        'view' => 'view',
        'create' => 'store',
        'update' => 'update',
        'delete' => 'destroy',
    ],
];
