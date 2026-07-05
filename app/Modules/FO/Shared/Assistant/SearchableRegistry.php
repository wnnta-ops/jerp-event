<?php

namespace App\Modules\FO\Shared\Assistant;

use App\Modules\FO\Shared\Assistant\Entities\CustomerEntity;
use App\Modules\FO\Shared\Assistant\Entities\GenericEntity;
use App\Modules\FO\Shared\Assistant\Entities\KontrakEntity;
use App\Modules\FO\Shared\Assistant\Entities\PembayaranEntity;
use App\Modules\FO\Shared\Assistant\Entities\TagihanEntity;

/**
 * Daftar terpusat entitas data FO yang bisa dibaca asisten (READ-ONLY).
 *
 * Menambah cakupan = tambah 1 baris di daftar (dedikasi untuk logika kompleks,
 * atau GenericEntity berbasis konfigurasi untuk yang lain). Semua tunduk gerbang
 * izin per entitas (fo.<slug>.index) di DataSearchTool.
 */
class SearchableRegistry
{
    /** @var array<string,SearchableEntity> */
    private array $entities = [];

    public function __construct()
    {
        $list = [
            // Entitas dengan logika khusus (kelas tersendiri).
            new CustomerEntity,
            new KontrakEntity,
            new PembayaranEntity,
            new TagihanEntity,
        ];

        foreach ($this->genericSpecs() as $s) {
            $list[] = new GenericEntity(
                keyName: $s['key'],
                labelName: $s['label'],
                permissionSlug: $s['perm'],
                modelClass: $s['model'],
                columns: $s['cols'],
                dateColumn: $s['date'] ?? null,
                statusColumn: $s['status'] ?? null,
                searchColumns: $s['search'] ?? [],
                with: $s['with'] ?? [],
                projectVia: $s['project'] ?? null,
            );
        }

        foreach ($list as $e) {
            $this->entities[$e->key()] = $e;
        }
    }

    /** @return array<string,SearchableEntity> */
    public function all(): array
    {
        return $this->entities;
    }

    public function get(string $key): ?SearchableEntity
    {
        return $this->entities[$key] ?? null;
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->entities);
    }

    /** Konfigurasi entitas FO generik. @return array<int,array<string,mixed>> */
    private function genericSpecs(): array
    {
        $FO = 'App\\Modules\\FO\\';

        return [
            [
                'key' => 'reservasi', 'label' => 'Reservasi / Booking', 'perm' => 'reservasi',
                'model' => $FO.'MarketingSales\\Models\\Reservasi',
                'cols' => [
                    'No. Reservasi' => ['no_reservasi'], 'Tgl' => ['tgl_reservasi', 'date'],
                    'Customer' => ['customer.full_name'], 'Unit' => ['unit.kode_unit'],
                    'Harga Jual' => ['harga_jual', 'rupiah'], 'Booking Fee' => ['booking_fee', 'rupiah'],
                    'Kadaluarsa' => ['tgl_kadaluarsa', 'date'], 'Status' => ['status'],
                ],
                'date' => 'tgl_reservasi', 'status' => 'status',
                'search' => ['no_reservasi', 'customer.full_name'], 'with' => ['customer', 'unit'],
                'project' => 'customer.project',
            ],
            [
                'key' => 'unit', 'label' => 'Unit / Kavling', 'perm' => 'unit',
                'model' => $FO.'MarketingSales\\Models\\Unit',
                'cols' => [
                    'Kode Unit' => ['kode_unit'], 'Blok' => ['blok'], 'No. Unit' => ['no_unit'],
                    'Tipe' => ['tipe_properti'], 'Luas Tanah' => ['luas_tanah'], 'Luas Bangunan' => ['luas_bangunan'],
                    'Harga Jual' => ['harga_jual', 'rupiah'], 'Status' => ['status'], 'Aktif' => ['is_active', 'bool'],
                ],
                'status' => 'status', 'search' => ['kode_unit', 'blok', 'no_unit'], 'project' => 'project',
            ],
            [
                'key' => 'agent', 'label' => 'Sales / Agent', 'perm' => 'agent',
                'model' => $FO.'Komisi\\Models\\Agent',
                'cols' => [
                    'No. Sales' => ['code'], 'Nama' => ['name'], 'Agensi' => ['agency_name'],
                    'Jenis' => ['jenis'], 'Level' => ['salesLevel.name'], 'Telepon' => ['phone'],
                    'Komisi Default %' => ['komisi_persen_default'], 'Aktif' => ['is_active', 'bool'],
                ],
                'search' => ['code', 'name', 'agency_name'], 'with' => ['salesLevel'],
            ],
            [
                'key' => 'bkm', 'label' => 'Kwitansi / BKM', 'perm' => 'bkm',
                'model' => $FO.'FinanceAR\\Models\\Bkm',
                'cols' => [
                    'No. BKM' => ['no_bkm'], 'Tgl' => ['tgl_bkm', 'date'], 'Customer' => ['customer.full_name'],
                    'Bank' => ['bank.name'], 'Jumlah' => ['amount', 'rupiah'], 'Jenis' => ['jenis_pembayaran'],
                    'No. Referensi' => ['no_referensi'], 'Status' => ['status'],
                ],
                'date' => 'tgl_bkm', 'status' => 'status',
                'search' => ['no_bkm', 'customer.full_name'], 'with' => ['customer', 'bank'],
                'project' => 'customer.project',
            ],
            [
                'key' => 'faktur', 'label' => 'Faktur Penjualan', 'perm' => 'fp',
                'model' => $FO.'FinanceAR\\Models\\Fp',
                'cols' => [
                    'No. Faktur' => ['no_fp'], 'Tgl' => ['tgl_faktur', 'date'], 'Tgl Terima' => ['tgl_terima', 'date'],
                    'No. Kontrak' => ['kontrak.no_kontrak'], 'Customer' => ['kontrak.customer.full_name'],
                    'No. Kwitansi' => ['no_kwitansi'], 'Status' => ['status'],
                ],
                'date' => 'tgl_faktur', 'status' => 'status',
                'search' => ['no_fp', 'kontrak.no_kontrak'], 'with' => ['kontrak.customer'], 'project' => 'project',
            ],
            [
                'key' => 'bilyet_giro', 'label' => 'Bilyet Giro / Cek', 'perm' => 'bg',
                'model' => $FO.'FinanceAR\\Models\\Bg',
                'cols' => [
                    'No. BG' => ['no_bg'], 'Tgl' => ['tgl_bg', 'date'], 'Jatuh Tempo' => ['tgl_jatuh_tempo', 'date'],
                    'Customer' => ['customer.full_name'], 'Bank' => ['bank.name'],
                    'Jumlah' => ['amount', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_bg', 'status' => 'status',
                'search' => ['no_bg', 'customer.full_name'], 'with' => ['customer', 'bank'],
                'project' => 'customer.project',
            ],
            [
                'key' => 'refund', 'label' => 'Refund / CB', 'perm' => 'refund',
                'model' => $FO.'FinanceAR\\Models\\Refund',
                'cols' => [
                    'No. Refund' => ['no_refund'], 'Tgl' => ['tgl_refund', 'date'], 'Jenis' => ['jenis'],
                    'Customer' => ['customer.full_name'], 'Nilai Kotor' => ['nilai_kotor', 'rupiah'],
                    'Biaya Admin' => ['biaya_admin', 'rupiah'], 'Nilai Refund' => ['nilai_refund', 'rupiah'],
                    'Status' => ['status'],
                ],
                'date' => 'tgl_refund', 'status' => 'status',
                'search' => ['no_refund', 'customer.full_name'], 'with' => ['customer'],
                'project' => 'customer.project',
            ],
            [
                'key' => 'transfer_anonim', 'label' => 'Transfer Anonim', 'perm' => 'transfer_anonim',
                'model' => $FO.'FinanceAR\\Models\\TransferAnonim',
                'cols' => [
                    'Tgl Transfer' => ['tgl_transfer', 'date'], 'Bank' => ['bank.name'],
                    'Jumlah' => ['amount', 'rupiah'], 'Ket. Bank' => ['keterangan_bank'],
                    'Customer' => ['customer.full_name'], 'Status' => ['status'],
                ],
                'date' => 'tgl_transfer', 'status' => 'status',
                'search' => ['keterangan_bank', 'customer.full_name'], 'with' => ['bank', 'customer'],
            ],
            [
                'key' => 'gmc', 'label' => 'GMC / Gimmick', 'perm' => 'gmc',
                'model' => $FO.'FinanceAR\\Models\\Gmc',
                'cols' => [
                    'No. GMC' => ['no_gmc'], 'Tgl' => ['tgl', 'date'], 'Customer' => ['customer.full_name'],
                    'No. Kontrak' => ['kontrak.no_kontrak'], 'Nama Gimmick' => ['nama'],
                    'Nilai' => ['nilai', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl', 'status' => 'status',
                'search' => ['no_gmc', 'customer.full_name'], 'with' => ['customer', 'kontrak'],
                'project' => 'customer.project',
            ],
            [
                'key' => 'komisi', 'label' => 'Komisi', 'perm' => 'realisasi_komisi',
                'model' => $FO.'Komisi\\Models\\Komisi',
                'cols' => [
                    'No. Komisi' => ['no_komisi'], 'Tgl' => ['tgl_komisi', 'date'], 'Sales' => ['agent.name'],
                    'No. Kontrak' => ['kontrak.no_kontrak'], 'DPP' => ['dpp', 'rupiah'],
                    'Komisi' => ['amount', 'rupiah'], 'Komisi Net' => ['amount_net', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_komisi', 'status' => 'status',
                'search' => ['no_komisi', 'agent.name'], 'with' => ['agent', 'kontrak'],
                'project' => 'kontrak.customer.project',
            ],
            [
                'key' => 'blt', 'label' => 'BLT', 'perm' => 'realisasi_blt',
                'model' => $FO.'Komisi\\Models\\Blt',
                'cols' => [
                    'No. BLT' => ['no_blt'], 'Tgl' => ['tgl_blt', 'date'], 'Penerima' => ['nama_user'],
                    'Level' => ['level_type'], 'No. Kontrak' => ['kontrak.no_kontrak'],
                    'Nilai BLT' => ['nilai_blt', 'rupiah'], 'Nett' => ['nett', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_blt', 'status' => 'status',
                'search' => ['no_blt', 'nama_user'], 'with' => ['kontrak'],
                'project' => 'kontrak.customer.project',
            ],
            [
                'key' => 'reward', 'label' => 'Reward Sales', 'perm' => 'reward',
                'model' => $FO.'Komisi\\Models\\Reward',
                'cols' => [
                    'No. Reward' => ['no_reward'], 'Tgl' => ['tgl_reward', 'date'], 'Sales' => ['agent.name'],
                    'Jenis' => ['jenis'], 'Nama Reward' => ['nama_reward'],
                    'Nilai' => ['amount', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_reward', 'status' => 'status',
                'search' => ['no_reward', 'agent.name'], 'with' => ['agent'],
            ],
            [
                'key' => 'collection', 'label' => 'Surat SP (Peringatan)', 'perm' => 'collection',
                'model' => $FO.'Collection\\Models\\SpLetter',
                'cols' => [
                    'No. Surat' => ['no_surat'], 'Level' => ['level'], 'Tgl' => ['tgl_surat', 'date'],
                    'Customer' => ['nama'], 'No. Kontrak' => ['kontrak.no_kontrak'],
                    'Jatuh Tempo' => ['jatuh_tempo', 'date'], 'Kekurangan' => ['total_kekurangan', 'rupiah'],
                    'Denda' => ['total_denda', 'rupiah'],
                ],
                'date' => 'tgl_surat', 'status' => 'level',
                'search' => ['no_surat', 'nama'], 'with' => ['kontrak'],
                'project' => 'kontrak.customer.project',
            ],
            [
                'key' => 'nup', 'label' => 'Pendaftaran NUP', 'perm' => 'nup',
                'model' => $FO.'Launching\\Models\\NupRegistration',
                'cols' => [
                    'No. NUP' => ['no_nup'], 'Tgl Daftar' => ['tgl_daftar', 'date'], 'Pendaftar' => ['nama_pendaftar'],
                    'Telepon' => ['phone'], 'Event' => ['event.name'], 'Tipe' => ['tipe_nup'],
                    'UTJ' => ['utj_amount', 'rupiah'], 'Verifikasi' => ['verif_status'], 'Status' => ['status'],
                ],
                'date' => 'tgl_daftar', 'status' => 'status',
                'search' => ['no_nup', 'nama_pendaftar'], 'with' => ['event'],
            ],
            [
                'key' => 'nup_event', 'label' => 'Event Launching (NUP)', 'perm' => 'nup_event',
                'model' => $FO.'Launching\\Models\\NupEvent',
                'cols' => [
                    'Kode' => ['code'], 'Nama Event' => ['name'], 'Tgl Launching' => ['tgl_launching', 'date'],
                    'Proyek' => ['project.name'], 'Kuota' => ['kuota'], 'Booking Fee' => ['booking_fee', 'rupiah'],
                    'Buka Registrasi' => ['open_registration_at', 'date'], 'Status' => ['status'],
                ],
                'date' => 'tgl_launching', 'status' => 'status',
                'search' => ['code', 'name'], 'with' => ['project'], 'project' => 'project',
            ],
            [
                'key' => 'pengalihan_hak', 'label' => 'Pengalihan Hak', 'perm' => 'pengalihan_hak',
                'model' => $FO.'MarketingSales\\Models\\PengalihanHak',
                'cols' => [
                    'No. Pengalihan' => ['no_pengalihan'], 'Tgl' => ['tgl_pengalihan', 'date'],
                    'No. Kontrak' => ['kontrak.no_kontrak'], 'Customer Lama' => ['customerLama.full_name'],
                    'Customer Baru' => ['customerBaru.full_name'], 'Biaya Admin' => ['biaya_admin', 'rupiah'],
                    'Status' => ['status'],
                ],
                'date' => 'tgl_pengalihan', 'status' => 'status',
                'search' => ['no_pengalihan', 'customerBaru.full_name'],
                'with' => ['kontrak', 'customerLama', 'customerBaru'], 'project' => 'kontrak.customer.project',
            ],
            [
                'key' => 'pindah_unit', 'label' => 'Pindah Unit', 'perm' => 'pindah_unit',
                'model' => $FO.'MarketingSales\\Models\\PindahUnit',
                'cols' => [
                    'No. Pindah' => ['no_pindah'], 'Tgl' => ['tgl_pindah', 'date'], 'Customer' => ['customer.full_name'],
                    'No. Kontrak' => ['kontrak.no_kontrak'], 'Unit Lama' => ['unitLama.kode_unit'],
                    'Unit Baru' => ['unitBaru.kode_unit'], 'Biaya Admin' => ['biaya_admin', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_pindah', 'status' => 'status',
                'search' => ['no_pindah', 'customer.full_name'],
                'with' => ['customer', 'kontrak', 'unitLama', 'unitBaru'], 'project' => 'customer.project',
            ],
            [
                'key' => 'memo', 'label' => 'Memo (Debit/Kredit)', 'perm' => 'memo',
                'model' => $FO.'FinanceAR\\Models\\Memo',
                'cols' => [
                    'No. Memo' => ['no_memo'], 'Tgl' => ['tgl_memo', 'date'], 'Customer' => ['customer.full_name'],
                    'Jenis' => ['jenis'], 'Arah' => ['arah'], 'Jumlah' => ['amount', 'rupiah'], 'Status' => ['status'],
                ],
                'date' => 'tgl_memo', 'status' => 'status',
                'search' => ['no_memo', 'customer.full_name'], 'with' => ['customer'], 'project' => 'customer.project',
            ],
        ];
    }
}
