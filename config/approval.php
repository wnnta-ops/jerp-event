<?php

/**
 * Konfigurasi otorisasi Approval (siapa boleh menyetujui apa).
 *
 * Model J-ERP = 2-level (L1 endorse → L2/GM final). Otorisasi dipakai oleh
 * App\Policies\ApprovalPolicy dan ditegakkan di ApprovalService (chokepoint),
 * mobile API, dan aksi Filament.
 *
 * AMAN secara default: bila role belum diberi permission `.approve` di bawah,
 * HANYA super_admin yang bisa menyetujui (fail-closed). Berikan permission ke
 * role yang sesuai lewat menu Grup Akses, lalu sesuaikan `final_roles`.
 */
return [
    // Boleh menyetujui permohonan sendiri / merangkap L1+L2? (separation of duties)
    'allow_self_approval' => (bool) env('APPROVAL_ALLOW_SELF', false),

    // Role yang boleh memberi persetujuan FINAL (Level 2 / GM).
    // super_admin SELALU boleh (lihat ApprovalPolicy::before). Kosongkan [] = role apa pun
    // yang punya permission .approve boleh final (tidak disarankan).
    'final_roles' => ['super_admin', 'direktur'],

    // Permission `.approve` per jenis approval (katalog di config/access_matrix.php).
    // Aktor WAJIB memiliki permission ini untuk bertindak pada jenis terkait.
    'jenis_permission' => [
        'DISKON' => 'fo.approval_diskon.approve',
        'DISKON_SPRS' => 'fo.approval_diskon.approve',
        'BATAL_KONTRAK' => 'fo.approval_pembatalan.approve',
        'ADJ_HARGA' => 'fo.approval_adjustment.approve',
        'RESCHEDULE' => 'fo.approval_reschedule.approve',
        'GANTI_NAMA' => 'fo.approval_pengalihan_hak.approve',
        'GANTI_UNIT' => 'fo.approval_pindah_unit.approve',
        'REFUND' => 'fo.refund.approve',
        'BLT' => 'fo.pengajuan_blt.approve',
        'KOMISI' => 'fo.pengajuan_komisi.approve',
        'LAIN' => 'fo.approval_customize.approve',
    ],

    // Permission default bila jenis tak terdaftar di atas.
    'fallback_permission' => 'fo.approval_customize.approve',
];
