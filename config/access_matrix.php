<?php

/**
 * Katalog Hak Akses (matrix permission) — selaras tampilan legacy "Grup Akses".
 * Dipakai RoleResource (form matrix) + AccessMatrixSeeder (buat permission).
 *
 * Nama permission = "{area}.{action}". Aksi standar: index/store/update/destroy/print/approve.
 * Tiap modul = grup baris; tiap baris (area) punya checkbox per aksi.
 */

$actions = [
    'index' => 'Lihat',
    'store' => 'Buat',
    'update' => 'Ubah',
    'destroy' => 'Hapus',
    'print' => 'Cetak',
    'approve' => 'Approve',
];

return [
    'actions' => $actions,

    // modul => [ area_key => Label ]
    'modules' => [
        'FO · Marketing & Sales' => [
            'fo.customer' => 'Master Customer',
            'fo.reservasi' => 'Master Booking',
            'fo.kontrak' => 'Kontrak (SPRS)',
            'fo.spr' => 'SPR',
            'fo.adjustment' => 'Adjustment Kontrak',
            'fo.pengalihan_hak' => 'Pengalihan Hak',
            'fo.pindah_unit' => 'Pindah Unit',
            'fo.pembatalan' => 'Pembatalan Kontrak',
        ],
        'FO · Booked Manual' => [
            'fo.verifikasi_booked' => 'Verifikasi Data',
            'fo.pilih_unit' => 'Pilih Unit',
        ],
        'FO · Finance AR' => [
            'fo.tts' => 'TTS',
            'fo.bkm' => 'Kwitansi (BKM)',
            'fo.va' => 'Virtual Account',
            'fo.memo' => 'Memo',
            'fo.fp' => 'Faktur Pajak',
            'fo.bg' => 'Bilyet Giro',
            'fo.refund' => 'Refund / CB',
            'fo.transfer_anonim' => 'Transfer Anonim',
        ],
        'FO · Approval' => [
            'fo.approval_pengalihan_hak' => 'Approval Pengalihan Hak',
            'fo.approval_pindah_unit' => 'Approval Pindah Unit',
            'fo.approval_pembatalan' => 'Approval Pembatalan',
            'fo.approval_diskon' => 'Approval Diskon',
            'fo.approval_adjustment' => 'Approval Adjustment',
            'fo.approval_reschedule' => 'Approval Reschedule',
            'fo.approval_customize' => 'Approval Customize',
        ],
        'FO · Komisi & BLT' => [
            'fo.skema_komisi' => 'Skema Komisi',
            'fo.termin_komisi' => 'Termin Komisi',
            'fo.skema_blt' => 'Skema BLT',
            'fo.termin_blt' => 'Termin BLT',
            'fo.generate_komisi' => 'Generate Komisi',
            'fo.pengajuan_komisi' => 'Pengajuan Komisi',
            'fo.realisasi_komisi' => 'Realisasi Komisi',
            'fo.generate_blt' => 'Generate BLT',
            'fo.pengajuan_blt' => 'Pengajuan BLT',
            'fo.realisasi_blt' => 'Realisasi BLT',
        ],
        'FO · Launching (NUP)' => [
            'fo.nup' => 'Registrasi NUP',
            'fo.verifikasi_nup' => 'Verifikasi NUP',
            'fo.aktivasi_nup' => 'Aktivasi NUP',
            'fo.closing_nup' => 'Closing NUP',
        ],
        'BO · Accounting' => [
            'bo.gl' => 'General Ledger (Jurnal)',
            'bo.ar' => 'Accounts Receivable',
            'bo.ap' => 'Accounts Payable',
            'bo.cb' => 'Cash & Bank',
            'bo.inventory' => 'Inventory',
            'bo.hr' => 'HR / Payroll',
        ],
        'BankLoan (KPR)' => [
            'bankloan.contract' => 'Kontrak KPR',
            'bankloan.realization' => 'Realisasi Akad (RA)',
            'bankloan.retention' => 'Retensi (PR/RR)',
            'bankloan.mom' => 'Minutes of Meeting',
        ],
        'Backup & System' => [
            'backup.manage' => 'Backup / Restore',
            'system.user' => 'Pengguna',
            'system.role' => 'Grup Akses',
            'system.master' => 'Master Data',
            'system.numerator' => 'Penomoran',
            'system.settings' => 'Pengaturan',
        ],
    ],
];
