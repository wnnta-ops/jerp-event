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
    'peran_super' => ['super_admin', 'sup'],

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
    | Izin per modul di dashboard pemilih (/dashboard). Dipakai untuk MENYEMBUNYIKAN
    | kartunya sekaligus MENJAGA rutenya — menyembunyikan kartu saja hanya kosmetik,
    | karena alamat seperti /sso/to-legal tetap bisa diketik langsung.
    */
    'modul' => [
        'fo' => 'panel.fo',
        'bo' => 'panel.bo',
        'backup' => 'panel.backup',
        'system' => 'panel.system',
        'ai' => 'panel.ai',
        'legal' => 'panel.legal',
        'sales' => 'panel.sales',
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

        // FO — approval (dipasang bersama penjagaan menu otomatis)
        'ApprovalAdjustmentResource' => 'fo.approval_adjustment',
        'ApprovalDiskonResource' => 'fo.approval_diskon',
        'ApprovalPembatalanResource' => 'fo.approval_pembatalan',
        'ApprovalPengalihanHakResource' => 'fo.approval_pengalihan_hak',
        'ApprovalPindahUnitResource' => 'fo.approval_pindah_unit',
        'ApprovalRescheduleResource' => 'fo.approval_reschedule',

        // FO — operasi kontrak & insentif
        'PengalihanHakResource' => 'fo.pengalihan_hak',
        'PindahUnitResource' => 'fo.pindah_unit',
        'SkemaBltResource' => 'fo.skema_blt',
        'SkemaKomisiResource' => 'fo.skema_komisi',
        'SprResource' => 'fo.spr',
        'TerminBltResource' => 'fo.termin_blt',
        'TerminKomisiResource' => 'fo.termin_komisi',

        // Bank Loan & System
        'ContractResource' => 'bankloan.contract',
        'NumeratorResource' => 'system.numerator',

        // Sisanya — dilengkapi agar tiap resource bisa disembunyikan sendiri

        // panel Bankloan
        'AgreementRealizationResource' => 'bankloan.agreement_realization',
        'MinutesOfMeetingResource' => 'bankloan.minutes_of_meeting',
        'RequirementResource' => 'bankloan.requirement',
        'RetentionCategoryResource' => 'bankloan.retention_category',
        'RetentionRealizationResource' => 'bankloan.retention_realization',
        'RetentionSubmissionResource' => 'bankloan.retention_submission',

        // panel Bo
        'ApInvoiceResource' => 'bo.ap_invoice',
        'ArInvoiceResource' => 'bo.ar_invoice',
        'CbVoucherResource' => 'bo.cb_voucher',
        'ChartOfAccountResource' => 'bo.chart_of_account',
        'DepartemenResource' => 'bo.departemen',
        'FoboPostingResource' => 'bo.fobo_posting',
        'IvtCategoryResource' => 'bo.ivt_category',
        'IvtItemResource' => 'bo.ivt_item',
        'IvtLocationResource' => 'bo.ivt_location',
        'IvtMovementResource' => 'bo.ivt_movement',
        'JurnalResource' => 'bo.jurnal',
        'KaryawanResource' => 'bo.karyawan',
        'PurchaseOrderResource' => 'bo.purchase_order',
        'PurchaseRequestResource' => 'bo.purchase_request',
        'SupplierResource' => 'bo.supplier',

        // panel Fo
        'AgentResource' => 'fo.agent',
        'ApprovalBltResource' => 'fo.approval_blt',
        'ApprovalDiskonSprsResource' => 'fo.approval_diskon_sprs',
        'ApprovalKomisiResource' => 'fo.approval_komisi',
        'ApprovalResource' => 'fo.approval',
        'App\\Filament\\Fo\\Resources\\BankResource' => 'fo.bank',
        'BerkasLengkapResource' => 'fo.berkas_lengkap',
        'CollectionResource' => 'fo.collection',
        'DataPreferensiUnitResource' => 'fo.data_preferensi_unit',
        'GimmickResource' => 'fo.gimmick',
        'GmcResource' => 'fo.gmc',
        'KomisiResource' => 'fo.komisi',
        'LokasiResource' => 'fo.lokasi',
        'LogPesanResource' => 'fo.monitoring_pesan',
        'TemplatePesanResource' => 'fo.template_pesan',
        'WaTemplateResource' => 'fo.template_wa',
        'TemplateDokumenResource' => 'fo.template_dokumen',
        'PreferensiUnitResource' => 'fo.preferensi_unit',
        'PriceListResource' => 'fo.price_list',
        'RewardResource' => 'fo.reward',
        'SalesLevelResource' => 'fo.sales_level',
        'SalesTypeResource' => 'fo.sales_type',
        'SkemaResource' => 'fo.skema',
        'SkemaRewardResource' => 'fo.skema_reward',
        'SumberDataResource' => 'fo.sumber_data',
        'TahapResource' => 'fo.tahap',
        'TipeGimmickResource' => 'fo.tipe_gimmick',
        'TipeTarifResource' => 'fo.tipe_tarif',
        'UnitTypeResource' => 'fo.unit_type',

        // panel System
        'AuditLogResource' => 'system.audit_log',
        'AuditUserResource' => 'system.audit_user',
        'App\\Filament\\System\\Resources\\BankResource' => 'system.bank',
        'BoRoleResource' => 'system.bo_role',
        'BoUserResource' => 'system.bo_user',
        'CompanyResource' => 'system.company',
        'ProjectResource' => 'system.project',
        'TenantResource' => 'system.tenant',
    ],

    /*
    | Awalan izin per HALAMAN Filament (Pages), ditulis sebagai nama kelas pendek.
    | Dipisah dari 'resource' karena halaman tidak punya aksi CRUD — membukanya
    | cuma satu tindakan — sehingga izinnya selalu berakhiran 'index'.
    |
    | Halaman yang TIDAK tercantum di sini tidak ditegakkan sama sekali, sama
    | seperti resource, dan dilaporkan `akses:audit` sebagai belum berpenjaga.
    */
    'halaman' => [
        'AktivasiNup' => 'fo.aktivasi_nup',
        'ClosingNup' => 'fo.closing_nup',
        'PilihUnit' => 'fo.pilih_unit',
        'PindahUnit' => 'fo.pindah_unit',
        'GenerateBlt' => 'fo.generate_blt',
        'GenerateKomisi' => 'fo.generate_komisi',
        'PengajuanBlt' => 'fo.pengajuan_blt',
        'PengajuanKomisi' => 'fo.pengajuan_komisi',
        'RealisasiBlt' => 'fo.realisasi_blt',
        'RealisasiKomisi' => 'fo.realisasi_komisi',
        'LaporanKomisi' => 'fo.laporan_komisi',
        'LaporanBlt' => 'fo.laporan_blt',
        'RekonsiliasiEprop' => 'fo.rekonsiliasi_eprop',
        'UploadTargetSpr' => 'fo.upload_target_spr',

        // Sisanya — dilengkapi agar tiap halaman bisa disembunyikan sendiri

        // panel Backup
        'KelolaBackup' => 'backup.kelola_backup',
        'PengaturanBackup' => 'backup.pengaturan_backup',

        // panel Bo
        'ArAgingPage' => 'bo.ar_aging_page',
        'ClosingPeriodePage' => 'bo.closing_periode_page',

        // panel Fo
        'AdjustmentKontrak' => 'fo.adjustment_kontrak',
        'ApprovalLog' => 'fo.approval_log',
        'AsistenAi' => 'fo.asisten_ai',
        'LaporanPenjualan' => 'fo.laporan_penjualan',
        'PelunasanAngsuran' => 'fo.pelunasan_angsuran',
        'PembatalanKontrak' => 'fo.pembatalan_kontrak',
        'PembayaranAr' => 'fo.pembayaran_ar',
        'PengajuanKpr' => 'fo.pengajuan_kpr',
        'PengaturanFo' => 'fo.pengaturan_fo',
        'PetaUnitLive' => 'fo.peta_unit_live',
        'PilihUnitBooked' => 'fo.pilih_unit_booked',
        'RekomBatalCash' => 'fo.rekom_batal_cash',
        'ReminderAr' => 'fo.reminder_ar',
        'ReportNup' => 'fo.report_nup',
        'ReportXendit' => 'fo.report_xendit',
        'SegeraHadir' => 'fo.segera_hadir',
        'SetupApproval' => 'fo.setup_approval',
        'SetupHold' => 'fo.setup_hold',
        'SetupSp' => 'fo.setup_sp',
        'SetupTerminSp' => 'fo.setup_termin_sp',
        'SkemaWizard' => 'fo.skema_wizard',
        'TerbitkanSp' => 'fo.terbitkan_sp',
        'UploadKoordinat' => 'fo.upload_koordinat',
        'UploadKoordinatEditor' => 'fo.upload_koordinat_editor',
        'UploadPriceList' => 'fo.upload_price_list',
        'UploadSitePlan' => 'fo.upload_site_plan',
        'UploadUnit' => 'fo.upload_unit',
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
        'fo.cetak.kwitansi-bkm-pdf',
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
        // Melihat satu record dianggap bagian dari melihat daftarnya. Kosakata
        // izin yang sudah ada memang tidak mengenal akhiran '.view' — memaksakannya
        // berarti membuat satu izin baru per modul yang tak pernah dipakai siapa pun,
        // dan resource jadi bisa dibuka daftarnya tapi tidak isinya.
        'view' => 'index',
        'create' => 'store',
        'update' => 'update',
        'delete' => 'destroy',
    ],
];
