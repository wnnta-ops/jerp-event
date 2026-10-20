<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jurnal mana yang MILIK DOKUMEN (bon, invoice AP, kuitansi, aset, gaji, ...)?
 *
 * Jurnal milik dokumen tidak boleh dihapus dari menu Jurnal: dokumennya akan tetap tercatat
 * "sudah dijurnal" padahal jurnalnya hilang. Ia dibatalkan lewat dokumennya sendiri.
 *
 * Daftar tautannya dibaca dari skema (setiap kolom *journal_id / *journal_line_id), bukan
 * ditulis tangan — modul baru yang menyimpan journal_id otomatis ikut terlindungi, tanpa
 * perlu ada yang ingat mendaftarkannya di sini.
 */
class PemilikJurnal
{
    /** Tabel yang memang bagian dari jurnal itu sendiri, bukan pemiliknya. */
    private const BUKAN_PEMILIK = ['bs_journals', 'bs_journal_lines', 'bs_journal_status_logs'];

    private const LABEL = [
        'bs_cash_advances' => 'Bon Sementara',
        'bs_cash_advance_allocations' => 'Alokasi Bon Sementara',
        'bs_cash_transactions' => 'Transaksi Kas/Bank',
        'bs_ap_invoices' => 'Invoice Hutang (AP)',
        'bs_ap_kuitansis' => 'Kuitansi Hutang',
        'bs_ap_payments' => 'Pembayaran Hutang',
        'bs_ap_memorials' => 'Memorial Hutang',
        'bs_ar_invoices' => 'Invoice Piutang (AR)',
        'bs_ar_kuitansis' => 'Kuitansi Piutang',
        'bs_ar_payments' => 'Pembayaran Piutang',
        'bs_ar_memorials' => 'Memorial Piutang',
        'bs_fixed_assets' => 'Aset Tetap',
        'bs_depreciation_entries' => 'Penyusutan Aset',
        'bs_payroll_runs' => 'Penggajian',
        'bs_bank_rec_lines' => 'Rekonsiliasi Bank',
        'bs_giros' => 'Giro',
        'bs_outgoing_cheques' => 'Cek Keluar',
        'bs_purchase_orders' => 'Purchase Order',
        'bs_work_orders' => 'SPK',
        'bs_work_order_progress' => 'Progress SPK',
        'kpr_realizations' => 'Realisasi KPR',
        'kpr_retentions' => 'Retensi KPR',
        'kpr_retention_releases' => 'Pencairan Retensi KPR',
        'tts_receipts' => 'TTS',
        'fo_gl_postings' => 'Posting dari FO',
    ];

    /** @var array<int, array{0:string,1:string}>|null [tabel, kolom] */
    private ?array $kolom = null;

    /**
     * @param  array<int,int>  $ids
     * @return array<int,string> journal_id => nama dokumen pemiliknya (hanya yang bertuan)
     */
    public function untuk(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (! $ids) {
            return [];
        }

        $hasil = [];
        $idBaris = null;

        foreach ($this->kolom() as [$tabel, $kolom]) {
            $sisa = array_values(array_diff($ids, array_keys($hasil)));
            if (! $sisa) {
                break;
            }

            if (str_ends_with($kolom, 'journal_line_id')) {
                $idBaris ??= DB::table('bs_journal_lines')->whereIn('journal_id', $ids)->pluck('journal_id', 'id')->all();
                $cocok = DB::table($tabel)->whereIn($kolom, array_keys($idBaris) ?: [0])->distinct()->pluck($kolom)
                    ->map(fn ($l) => (int) ($idBaris[(int) $l] ?? 0))->all();
            } else {
                $q = DB::table($tabel)->whereIn($kolom, $sisa);
                // Dokumen yang sudah dihapus (soft delete) tidak lagi memiliki jurnalnya.
                if ($this->punyaDeletedAt($tabel)) {
                    $q->whereNull('deleted_at');
                }
                $cocok = $q->distinct()->pluck($kolom)->map(fn ($v) => (int) $v)->all();
            }

            foreach ($cocok as $jid) {
                if ($jid && in_array($jid, $sisa, true)) {
                    $hasil[$jid] = self::LABEL[$tabel] ?? (string) Str::of($tabel)->after('bs_')->replace('_', ' ')->title();
                }
            }
        }

        return $hasil;
    }

    /** @return array<int, array{0:string,1:string}> */
    private function kolom(): array
    {
        return $this->kolom ??= collect(DB::select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND (COLUMN_NAME LIKE '%journal\\_id' OR COLUMN_NAME LIKE '%journal\\_line\\_id')
                AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')"
        ))->reject(fn ($r) => in_array($r->t, self::BUKAN_PEMILIK, true))
            ->map(fn ($r) => [(string) $r->t, (string) $r->c])->values()->all();
    }

    /** @var array<string,bool> */
    private array $deletedAt = [];

    private function punyaDeletedAt(string $tabel): bool
    {
        return $this->deletedAt[$tabel] ??= \Illuminate\Support\Facades\Schema::hasColumn($tabel, 'deleted_at');
    }
}
