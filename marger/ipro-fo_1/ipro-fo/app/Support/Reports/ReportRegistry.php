<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * Registry definisi laporan per modul (config-driven).
 *
 * Tiap definisi:
 *  - label, group       : tampilan
 *  - table              : tabel utama
 *  - date               : kolom tanggal (untuk filter & tren)
 *  - status             : kolom status (untuk distribusi) — boleh null
 *  - amount             : kolom nominal (untuk total/sum) — boleh null
 *  - joins[]            : [table, localKey, foreignKey] (left join, untuk nama relasi)
 *  - columns{label}     : [sqlExpr, type]  type ∈ text|money|date|datetime
 */
final class ReportRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ── MASTER ──
            'customers' => [
                'label' => 'Customer', 'group' => 'Master', 'table' => 'customers',
                'date' => 'created_at', 'status' => 'status', 'amount' => null,
                'columns' => [
                    'Kode' => ['customers.code', 'text'],
                    'Nama' => ['customers.full_name', 'text'],
                    'No HP' => ['customers.phone', 'text'],
                    'Email' => ['customers.email', 'text'],
                    'Kota' => ['customers.city', 'text'],
                    'Status' => ['customers.status', 'text'],
                    'Terdaftar' => ['customers.created_at', 'date'],
                ],
            ],
            'units' => [
                'label' => 'Unit', 'group' => 'Master', 'table' => 'units',
                'date' => 'created_at', 'status' => 'status', 'amount' => 'current_price',
                'columns' => [
                    'Kode Unit' => ['units.unit_code', 'text'],
                    'Harga' => ['units.current_price', 'money'],
                    'Status' => ['units.status', 'text'],
                    'Dibuat' => ['units.created_at', 'date'],
                ],
            ],

            // ── SALES ──
            'holds' => [
                'label' => 'Hold Unit', 'group' => 'Sales', 'table' => 'unit_holds',
                'date' => 'held_at', 'status' => 'status', 'amount' => null,
                'joins' => [['units', 'unit_holds.unit_id', 'units.id'], ['customers', 'unit_holds.customer_id', 'customers.id']],
                'columns' => [
                    'Unit' => ['units.unit_code', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Status' => ['unit_holds.status', 'text'],
                    'Mulai' => ['unit_holds.held_at', 'datetime'],
                    'Berakhir' => ['unit_holds.expires_at', 'datetime'],
                ],
            ],
            'nup' => [
                'label' => 'NUP', 'group' => 'Sales', 'table' => 'nup_registrations',
                'date' => 'registered_at', 'status' => 'status', 'amount' => null,
                'joins' => [['customers', 'nup_registrations.customer_id', 'customers.id'], ['nup_schemes', 'nup_registrations.nup_scheme_id', 'nup_schemes.id']],
                'columns' => [
                    'Nomor' => ['nup_registrations.nup_number', 'text'],
                    'Antrian' => ['nup_registrations.queue_no', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Skema' => ['nup_schemes.name', 'text'],
                    'Status' => ['nup_registrations.status', 'text'],
                    'Bayar' => ['nup_registrations.payment_status', 'text'],
                    'Terdaftar' => ['nup_registrations.registered_at', 'date'],
                ],
            ],
            'bookings' => [
                'label' => 'Booking', 'group' => 'Sales', 'table' => 'bookings',
                'date' => 'booking_date', 'status' => 'status', 'amount' => 'total_price',
                'joins' => [['customers', 'bookings.customer_id', 'customers.id'], ['units', 'bookings.unit_id', 'units.id']],
                'columns' => [
                    'Nomor' => ['bookings.booking_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Unit' => ['units.unit_code', 'text'],
                    'Diskon' => ['bookings.discount_amount', 'money'],
                    'Total' => ['bookings.total_price', 'money'],
                    'Status' => ['bookings.status', 'text'],
                    'Tanggal' => ['bookings.booking_date', 'date'],
                ],
            ],
            'contracts' => [
                'label' => 'Kontrak (PPJB)', 'group' => 'Sales', 'table' => 'contracts',
                'date' => 'signed_at', 'status' => 'status', 'amount' => null,
                'joins' => [['bookings', 'contracts.booking_id', 'bookings.id']],
                'columns' => [
                    'Nomor' => ['contracts.contract_number', 'text'],
                    'Booking' => ['bookings.booking_number', 'text'],
                    'Status' => ['contracts.status', 'text'],
                    'PPJB' => ['contracts.ppjb_status', 'text'],
                    'Ditandatangani' => ['contracts.signed_at', 'date'],
                ],
            ],

            // ── FINANCE ──
            'tts' => [
                'label' => 'TTS (Penerimaan)', 'group' => 'Finance', 'table' => 'tts_receipts',
                'date' => 'received_date', 'status' => 'status', 'amount' => 'amount',
                'joins' => [
                    ['customers', 'tts_receipts.customer_id', 'customers.id'],
                    ['bookings', 'tts_receipts.booking_id', 'bookings.id'],
                    ['units', 'bookings.unit_id', 'units.id'],
                ],
                'columns' => [
                    'Nomor' => ['tts_receipts.tts_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'No Unit' => ['units.unit_code', 'text'],
                    'Metode' => ['tts_receipts.payment_method', 'text'],
                    'Jumlah' => ['tts_receipts.amount', 'money'],
                    'Status' => ['tts_receipts.status', 'text'],
                    'Tanggal' => ['tts_receipts.received_date', 'date'],
                ],
            ],
            'kuitansi' => [
                'label' => 'Kuitansi', 'group' => 'Finance', 'table' => 'kuitansis',
                'date' => 'issued_date', 'status' => 'status', 'amount' => 'amount',
                'joins' => [
                    ['customers', 'kuitansis.customer_id', 'customers.id'],
                    ['tts_receipts', 'kuitansis.tts_receipt_id', 'tts_receipts.id'],
                    ['bookings', 'tts_receipts.booking_id', 'bookings.id'],
                    ['units', 'bookings.unit_id', 'units.id'],
                ],
                'columns' => [
                    'Nomor' => ['kuitansis.kuitansi_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'No Unit' => ['units.unit_code', 'text'],
                    'Jumlah' => ['kuitansis.amount', 'money'],
                    'Status' => ['kuitansis.status', 'text'],
                    'Tanggal' => ['kuitansis.issued_date', 'date'],
                ],
            ],
            'memos' => [
                'label' => 'Memo', 'group' => 'Finance', 'table' => 'memos',
                'date' => 'memo_date', 'status' => 'status', 'amount' => 'amount',
                'columns' => [
                    'Nomor' => ['memos.memo_number', 'text'],
                    'Tipe' => ['memos.type', 'text'],
                    'Jumlah' => ['memos.amount', 'money'],
                    'Status' => ['memos.status', 'text'],
                    'Tanggal' => ['memos.memo_date', 'date'],
                ],
            ],
            'cashbacks' => [
                'label' => 'Cashback', 'group' => 'Finance', 'table' => 'cashbacks',
                'date' => 'created_at', 'status' => 'status', 'amount' => 'amount',
                'joins' => [['bookings', 'cashbacks.booking_id', 'bookings.id']],
                'columns' => [
                    'Booking' => ['bookings.booking_number', 'text'],
                    'Jumlah' => ['cashbacks.amount', 'money'],
                    'Status' => ['cashbacks.status', 'text'],
                    'Alasan' => ['cashbacks.reason', 'text'],
                    'Dibuat' => ['cashbacks.created_at', 'date'],
                ],
            ],
            'refunds' => [
                'label' => 'Refund', 'group' => 'Finance', 'table' => 'refunds',
                'date' => 'requested_at', 'status' => 'status', 'amount' => 'amount',
                'columns' => [
                    'Nomor' => ['refunds.refund_number', 'text'],
                    'Tipe' => ['refunds.refundable_type', 'text'],
                    'Jumlah' => ['refunds.amount', 'money'],
                    'Status' => ['refunds.status', 'text'],
                    'Diminta' => ['refunds.requested_at', 'date'],
                ],
            ],
            'faktur_pajak' => [
                'label' => 'Faktur Pajak', 'group' => 'Finance', 'table' => 'faktur_pajaks',
                'date' => 'issued_at', 'status' => 'status', 'amount' => 'total',
                'joins' => [['customers', 'faktur_pajaks.customer_id', 'customers.id']],
                'columns' => [
                    'Nomor' => ['faktur_pajaks.fp_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'DPP' => ['faktur_pajaks.dpp', 'money'],
                    'PPN' => ['faktur_pajaks.ppn', 'money'],
                    'Total' => ['faktur_pajaks.total', 'money'],
                    'Status' => ['faktur_pajaks.status', 'text'],
                    'Tanggal' => ['faktur_pajaks.issued_at', 'date'],
                ],
            ],
            'va' => [
                'label' => 'Virtual Account', 'group' => 'Finance', 'table' => 'virtual_accounts',
                'date' => 'created_at', 'status' => 'status', 'amount' => null,
                'joins' => [['customers', 'virtual_accounts.customer_id', 'customers.id']],
                'columns' => [
                    'VA' => ['virtual_accounts.va_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Status' => ['virtual_accounts.status', 'text'],
                    'Dibuat' => ['virtual_accounts.created_at', 'date'],
                ],
            ],
            'installments' => [
                'label' => 'Cicilan (Jadwal Bayar)', 'group' => 'Finance', 'table' => 'payment_schedules',
                'date' => 'due_date', 'status' => 'status', 'amount' => 'amount',
                'joins' => [['bookings', 'payment_schedules.booking_id', 'bookings.id']],
                'columns' => [
                    'Booking' => ['bookings.booking_number', 'text'],
                    'Termin' => ['payment_schedules.label', 'text'],
                    'Jumlah' => ['payment_schedules.amount', 'money'],
                    'Dibayar' => ['payment_schedules.paid_amount', 'money'],
                    'Status' => ['payment_schedules.status', 'text'],
                    'Jatuh Tempo' => ['payment_schedules.due_date', 'date'],
                ],
            ],

            // ── COMMISSION ──
            'commissions' => [
                'label' => 'Komisi', 'group' => 'Komisi', 'table' => 'commissions',
                'date' => 'created_at', 'status' => 'status', 'amount' => 'base_amount',
                'joins' => [['bookings', 'commissions.booking_id', 'bookings.id']],
                'columns' => [
                    'Booking' => ['bookings.booking_number', 'text'],
                    'Dasar Komisi' => ['commissions.base_amount', 'money'],
                    'Status' => ['commissions.status', 'text'],
                    'Dibuat' => ['commissions.created_at', 'date'],
                ],
            ],

            // ── KPR (BANK LOAN) ──
            'kpr' => [
                'label' => 'KPR', 'group' => 'KPR', 'table' => 'kpr_applications',
                'date' => 'created_at', 'status' => 'status', 'amount' => 'plafon_approved',
                'joins' => [['customers', 'kpr_applications.customer_id', 'customers.id']],
                'columns' => [
                    'Nomor' => ['kpr_applications.application_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Plafon Disetujui' => ['kpr_applications.plafon_approved', 'money'],
                    'Status' => ['kpr_applications.status', 'text'],
                    'Dibuat' => ['kpr_applications.created_at', 'date'],
                ],
            ],

            // ── COLLECTION ──
            'followups' => [
                'label' => 'Follow-up', 'group' => 'Collection', 'table' => 'followups',
                'date' => 'contacted_at', 'status' => 'status', 'amount' => null,
                'joins' => [['customers', 'followups.customer_id', 'customers.id']],
                'columns' => [
                    'Customer' => ['customers.full_name', 'text'],
                    'Channel' => ['followups.channel', 'text'],
                    'Hasil' => ['followups.result', 'text'],
                    'Status' => ['followups.status', 'text'],
                    'Dihubungi' => ['followups.contacted_at', 'datetime'],
                ],
            ],
            'sp' => [
                'label' => 'Surat Peringatan', 'group' => 'Collection', 'table' => 'surat_peringatans',
                'date' => 'issued_at', 'status' => 'status', 'amount' => null,
                'joins' => [['customers', 'surat_peringatans.customer_id', 'customers.id']],
                'columns' => [
                    'Nomor' => ['surat_peringatans.sp_number', 'text'],
                    'Customer' => ['customers.full_name', 'text'],
                    'Level' => ['surat_peringatans.level', 'text'],
                    'Status' => ['surat_peringatans.status', 'text'],
                    'Terbit' => ['surat_peringatans.issued_at', 'date'],
                ],
            ],

            // ── LEGAL ──
            'ppjb' => [
                'label' => 'PPJB (Dokumen)', 'group' => 'Legal', 'table' => 'ppjb_documents',
                'date' => 'signed_at', 'status' => 'status', 'amount' => null,
                'columns' => [
                    'Nomor' => ['ppjb_documents.ppjb_number', 'text'],
                    'Status' => ['ppjb_documents.status', 'text'],
                    'Ditandatangani' => ['ppjb_documents.signed_at', 'date'],
                ],
            ],
            'ajb' => [
                'label' => 'AJB', 'group' => 'Legal', 'table' => 'ajb_documents',
                'date' => 'signed_at', 'status' => 'status', 'amount' => null,
                'columns' => [
                    'Nomor' => ['ajb_documents.ajb_number', 'text'],
                    'Notaris' => ['ajb_documents.notary_name', 'text'],
                    'Status' => ['ajb_documents.status', 'text'],
                    'Ditandatangani' => ['ajb_documents.signed_at', 'date'],
                ],
            ],
            'bast' => [
                'label' => 'BAST', 'group' => 'Legal', 'table' => 'bast_documents',
                'date' => 'handed_over_at', 'status' => 'status', 'amount' => null,
                'columns' => [
                    'Nomor' => ['bast_documents.bast_number', 'text'],
                    'Status' => ['bast_documents.status', 'text'],
                    'Serah Terima' => ['bast_documents.handed_over_at', 'date'],
                ],
            ],
            'sertifikat' => [
                'label' => 'Sertifikat', 'group' => 'Legal', 'table' => 'sertifikat_documents',
                'date' => 'issued_at', 'status' => null, 'amount' => null,
                'columns' => [
                    'Serial' => ['sertifikat_documents.serial_number', 'text'],
                    'Tipe' => ['sertifikat_documents.type', 'text'],
                    'Terbit' => ['sertifikat_documents.issued_at', 'date'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
