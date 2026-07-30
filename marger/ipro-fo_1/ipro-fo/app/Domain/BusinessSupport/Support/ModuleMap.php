<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

/**
 * Peta struktur menu 8 sub-modul Business Support (diambil dari user-manual eProperty).
 * Dipakai halaman Overview & halaman tiap sub-modul. Item dengan 'route' sudah
 * tersedia (Phase 1); selebihnya menyusul bertahap (Phase 2+).
 */
final class ModuleMap
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'purchasing' => [
                'label' => 'Purchasing', 'icon' => 'ShoppingCart',
                'desc'  => 'Pengadaan barang & jasa, pembentukan Purchase Order (PO).',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Kategori Pembayaran'],
                    'Transaksi'=> ['Registrasi Purchase Order', 'Registrasi Termin PO', 'Approval PO', 'Close PO', 'Adjustment PPN', 'Revisi PO'],
                    'Master'   => ['Purchase Order'],
                    'Laporan'  => ['PO Outstanding (Qty)', 'Ringkasan Pesanan', 'Laporan PO', 'Kartu Hutang Supplier'],
                ],
            ],
            'inventory' => [
                'label' => 'Inventory', 'icon' => 'Boxes',
                'desc'  => 'Stok/gudang: permintaan barang (FPB), PR, penerimaan & pengeluaran barang.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Satuan Stok', 'Kategori Stok', 'Lokasi Stok (Gudang)', 'Kategori Jurnal'],
                    'Transaksi'=> ['Registrasi/Upload Stok', 'Form Permintaan Barang (FPB)', 'Approval FPB', 'Review Stok', 'Purchase Requisition (PR)', 'Penerimaan Barang', 'Pengeluaran Barang'],
                    'Master'   => ['Stok', 'Permintaan Barang', 'Purchase Requisition', 'Penerimaan', 'Pengeluaran'],
                    'Laporan'  => ['Kartu Stok (Nominal/Qty)', 'Laporan Persediaan', 'Penerimaan/Pengeluaran Barang'],
                ],
            ],
            'ap' => [
                'label' => 'Account Payable (AP)', 'icon' => 'FileDown',
                'desc'  => 'Hutang usaha: registrasi invoice hutang, penyesuaian, posting PO, pelunasan.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Kategori Invoice', 'Repair Data'],
                    'Transaksi'=> ['Registrasi Invoice Hutang', 'Penghapusan Nilai Hutang', 'Posting Invoice dari FO', 'Pelunasan Hutang'],
                    'Master'   => ['Invoice Hutang'],
                    'Laporan'  => ['Kartu Hutang', 'Aging Hutang'],
                ],
            ],
            'ar' => [
                'label' => 'Account Receivable (AR)', 'icon' => 'FileUp',
                'desc'  => 'Piutang usaha: registrasi invoice piutang, denda, pelunasan tunai/giro.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Kategori Invoice', 'Repair Data', 'Kelengkapan Berkas'],
                    'Transaksi'=> ['Registrasi Invoice Piutang', 'Realisasi Denda', 'Penghapusan Nilai Piutang', 'Reschedule Jatuh Tempo', 'Pelunasan (Tunai)', 'Pelunasan (Cek/Giro)'],
                    'Master'   => ['Invoice Piutang', 'Kuitansi Piutang', 'Memorial Piutang'],
                    'Laporan'  => ['Kartu Piutang', 'Aging Piutang', 'Proyeksi Piutang', 'Penerimaan Kasir', 'Invoice'],
                ],
            ],
            'cashbook' => [
                'label' => 'CashBook', 'icon' => 'Wallet',
                'desc'  => 'Transaksi kas & bank: BKM (masuk), BKK (keluar), giro, rekonsiliasi.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Rekening Kas/Bank', 'Kategori Transaksi'],
                    'Transaksi'=> ['Bukti Kas Masuk (BKM)', 'Bukti Kas Keluar (BKK)', 'Transfer Antar Rekening', 'Cek/Giro'],
                    'Master'   => ['Voucher Kas/Bank', 'Mutasi Rekening'],
                    'Laporan'  => ['Buku Kas/Bank', 'Mutasi Kas/Bank', 'Saldo Rekening'],
                ],
            ],
            'cogs' => [
                'label' => 'COGS (HPP)', 'icon' => 'Calculator',
                'desc'  => 'Harga pokok penjualan: pembebanan biaya proyek ke unit terjual.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Kategori Biaya Proyek'],
                    'Transaksi'=> ['Alokasi Biaya Proyek', 'Perhitungan HPP', 'Posting HPP'],
                    'Master'   => ['HPP per Unit', 'Biaya Proyek'],
                    'Laporan'  => ['Laporan HPP', 'Rekap Biaya Proyek'],
                ],
            ],
            'contractor' => [
                'label' => 'Contractor Management', 'icon' => 'HardHat',
                'desc'  => 'Manajemen kontraktor: kontrak kerja (SPK), termin, progress, retensi, pembayaran.',
                'sections' => [
                    'Setup'    => ['Transaction Rule', 'Kategori Pekerjaan'],
                    'Transaksi'=> ['Registrasi SPK', 'Termin Pembayaran', 'Progress/Opname', 'Retensi', 'Pembayaran Kontraktor'],
                    'Master'   => ['Kontraktor', 'SPK', 'Termin'],
                    'Laporan'  => ['Realisasi SPK', 'Outstanding Termin', 'Retensi'],
                ],
            ],
            'budget' => [
                'label' => 'Budget Control', 'icon' => 'PieChart',
                'desc'  => 'Anggaran per cost center: upload, approval, alokasi, koreksi, realisasi.',
                'sections' => [
                    'Upload'   => ['Upload Budget', 'Approval Budget'],
                    'Alokasi'  => ['Alokasi Budget', 'Approval Alokasi'],
                    'Koreksi'  => ['Koreksi Budget', 'Approval Koreksi'],
                    'Master'   => ['Budget Control', 'Alokasi', 'Koreksi'],
                    'Laporan'  => ['Master Budget', 'Realisasi Budget', 'Alokasi Budget'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
