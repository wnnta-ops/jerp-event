<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris buku besar sebuah akun, berikut dokumen yang melahirkannya.
 *
 * Dipakai layar-layar yang harus SAMA rinciannya dengan Detail Transaksi Jurnal untuk akun
 * tertentu — UM Penjualan (akun Uang Muka Penjualan) dan Rekap PPh Dipotong (akun Hutang PPh).
 * Keduanya menghadapi masalah yang sama: layarnya dibangun dari tabel dokumen operasional,
 * sedangkan akunnya di buku besar ditulis oleh jauh lebih banyak jalur. Baris yang lahir dari
 * jalur lain tidak muncul di layar, dan tidak ada satu pun galat yang memberitahu.
 *
 * Kaitan di basis data ini TERBALIK: bs_journal_lines tidak punya kolom penaut ke dokumen
 * sumbernya sama sekali (sudah diverifikasi ke skema), sedangkan 30 tabel dokumen menyimpan
 * journal_id yang menunjuk ke bs_journals.id. Kelas ini menempuh arah balik itu secara BATCH —
 * satu query per tabel dokumen, bukan N+1 per jurnal — mengikuti pola JournalPartyResolver.
 *
 * @see JournalPartyResolver pola penelusuran balik yang sama, untuk nama pihak
 * @see UangMukaPenjualanLedger pemakai; menambahkan aturan khusus akun Uang Muka
 */
final class BukuBesarDokumen
{
    /**
     * Batas id per putaran, disamakan dengan JournalPartyResolver — menjaga jumlah placeholder
     * jauh di bawah batas MySQL walau operator menarik rentang bertahun-tahun.
     */
    private const CHUNK = 5000;

    /**
     * Tabel dokumen yang menyimpan journal_id, urut dari yang paling spesifik.
     * Bentuk: [tabel, kolom jurnal, kolom nomor, kolom tambahan, jenis, label].
     *
     * @var array<int,array{0:string,1:array<int,string>,2:string,3:array<int,string>,4:string,5:string}>
     */
    private const DOKUMEN = [
        ['bs_booked_revenues', ['booking_journal_id', 'recognition_journal_id', 'cancel_journal_id'], 'br_number', ['customer_id'], 'booked_revenue', 'Booked Revenue'],
        ['bs_ap_invoices', ['journal_id'], 'invoice_number', ['supplier_id', 'customer_id'], 'ap_invoice', 'Invoice Hutang'],
        ['bs_ap_kuitansis', ['journal_id', 'void_journal_id'], 'kuitansi_number', ['supplier_id'], 'ap_kuitansi', 'Kuitansi Hutang'],
        ['bs_ap_memorials', ['journal_id'], 'memorial_number', ['supplier_id'], 'ap_memorial', 'Memorial Hutang'],
        // bs_ap_payments memang tidak punya kolom nomor sendiri — id-nya yang jadi penanda.
        ['bs_ap_payments', ['journal_id'], 'id', [], 'ap_payment', 'Pembayaran Hutang'],
        ['bs_ar_invoices', ['journal_id'], 'invoice_number', ['customer_id', 'booking_id'], 'ar_invoice', 'Invoice Piutang'],
        ['bs_ar_kuitansis', ['journal_id'], 'kuitansi_number', ['customer_id'], 'ar_kuitansi', 'Kuitansi Piutang'],
        ['bs_ar_memorials', ['journal_id'], 'memorial_number', ['customer_id'], 'ar_memorial', 'Memorial Piutang'],
        ['bs_payroll_runs', ['journal_id'], 'run_number', [], 'payroll', 'Payroll'],
        ['bs_faktur_keluaran_entries', ['convert_journal_id', 'journal_id'], 'fp_number', ['customer_id', 'booking_id'], 'faktur_keluaran', 'Entri Faktur Keluaran'],
        ['tts_receipts', ['journal_id'], 'tts_number', ['customer_id', 'booking_id'], 'tts', 'TTS (UTJ/DP)'],
        ['kpr_retention_releases', ['journal_id'], 'id', [], 'retensi_release', 'Pelepasan Retensi DAJAM'],
        ['kpr_retentions', ['journal_id'], 'id', [], 'retensi', 'Retensi DAJAM'],
        ['kpr_realizations', ['journal_id'], 'id', [], 'pencairan', 'Pencairan KPR'],
        ['bs_outgoing_cheques', ['handover_journal_id', 'clear_journal_id', 'void_journal_id'], 'cheque_number', ['supplier_id'], 'cheque_out', 'BG/Cek Keluar'],
        ['bs_giros', ['receive_journal_id', 'settle_journal_id'], 'giro_number', [], 'giro', 'Giro'],
        ['bs_cash_transactions', ['journal_id'], 'trx_number', [], 'cash', 'Transaksi Kas/Bank'],
    ];

    /**
     * Baris jurnal pada akun-akun tertentu.
     *
     * Jurnal ber-status apa pun ikut (Baru/Final/Approved), karena Detail Transaksi Jurnal juga
     * menampilkan semuanya — menyaringnya di sini membuat rincian kedua layar tidak akan pernah
     * sama. Statusnya dibawa per baris supaya yang belum efektif tetap bisa dibedakan.
     *
     * @param  array<int,int>  $accountIds
     * @return Collection<int,BsJournalLine>
     */
    public function lines(array $accountIds, ?string $from = null, ?string $to = null): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($ids === []) {
            return collect();
        }

        return BsJournalLine::query()
            ->whereIn('account_id', $ids)
            // Lewat relasi jurnal agar global scope tenant & soft delete jurnal ikut berlaku;
            // bs_journal_lines sendiri tidak punya tenant_id maupun deleted_at.
            ->whereHas('journal', function ($q) use ($from, $to) {
                $q->when($from, fn ($x, $v) => $x->whereDate('journal_date', '>=', $v))
                    ->when($to, fn ($x, $v) => $x->whereDate('journal_date', '<=', $v));
            })
            ->with(['journal:id,journal_number,journal_date,status,source,reference,description', 'account:id,code,name'])
            ->get();
    }

    /**
     * Nilai bersih akun-akun tersebut per jurnal (kredit − debit).
     *
     * @param  array<int,int>  $journalIds
     * @param  array<int,int>  $accountIds
     * @return array<int,float>
     */
    public function netByJournal(array $journalIds, array $accountIds): array
    {
        $ids  = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        $akun = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($ids === [] || $akun === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $part) {
            $rows = DB::table('bs_journal_lines')
                ->whereIn('journal_id', $part)->whereIn('account_id', $akun)
                ->groupBy('journal_id')
                ->get(['journal_id', DB::raw('SUM(credit) AS c'), DB::raw('SUM(debit) AS d')]);
            foreach ($rows as $r) {
                $out[(int) $r->journal_id] = round((float) $r->c - (float) $r->d, 2);
            }
        }

        return $out;
    }

    /**
     * Dokumen sumber di balik tiap jurnal.
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,array{type:string,label:string,id:int,number:?string,customer_id:?int,supplier_id:?int,booking_id:?int}>
     */
    public function documentsFor(array $journalIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::CHUNK) {
            $out = [];
            foreach (array_chunk($ids, self::CHUNK) as $part) {
                $out += $this->documentChunk($part);
            }

            return $out;
        }

        return $this->documentChunk($ids);
    }

    /**
     * Kolom yang disebut DOKUMEN tetapi tidak ada di basis data.
     *
     * Penelusuran balik melewati kolom yang tidak ada supaya satu tabel yang belum dimigrasi
     * tidak menjatuhkan seluruh layar. Sifat memaafkan itu ada harganya: salah ketik nama kolom
     * juga ikut dimaafkan, dan penautnya diam-diam mati — barisnya tetap tampil, hanya kehilangan
     * nomor dokumen & pihaknya, tanpa satu pun galat. Metode ini membuat kekeliruan itu bisa
     * diperiksa oleh uji, bukan hanya oleh mata.
     *
     * Tabel yang memang TIDAK ADA di basis data ini dilewati diam-diam — itu wajar (fitur yang
     * belum dipasang); yang dilaporkan hanya kolom yang hilang dari tabel yang ada.
     *
     * @return array<int,string>
     */
    public function kolomHilang(): array
    {
        $hilang = [];
        foreach (self::DOKUMEN as [$tabel, $kolomJurnal, $kolomNomor, $tambahan]) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }
            foreach ([...$kolomJurnal, $kolomNomor, ...$tambahan] as $k) {
                if (! $this->punyaKolom($tabel, $k)) {
                    $hilang[] = $tabel.'.'.$k;
                }
            }
        }

        return $hilang;
    }

    // ── Penelusuran balik jurnal → dokumen ───────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function documentChunk(array $ids): array
    {
        $out = [];
        // Hanya isi bila belum terisi → tabel yang disebut lebih dulu menang.
        $fill = function (array $rows, string $type, string $label) use (&$out): void {
            foreach ($rows as $journalId => $d) {
                $out[(int) $journalId] ??= [
                    'type'        => $type,
                    'label'       => $label,
                    'id'          => (int) $d->id,
                    'number'      => isset($d->number) ? (string) $d->number : null,
                    'customer_id' => isset($d->customer_id) ? (int) $d->customer_id : null,
                    'supplier_id' => isset($d->supplier_id) ? (int) $d->supplier_id : null,
                    'booking_id'  => isset($d->booking_id) ? (int) $d->booking_id : null,
                ];
            }
        };

        foreach (self::DOKUMEN as [$tabel, $kolomJurnal, $kolomNomor, $tambahan, $jenis, $label]) {
            foreach ($kolomJurnal as $kol) {
                $fill($this->ambil($tabel, $kol, $kolomNomor, $tambahan, $ids), $jenis, $label);
            }
        }

        return $out;
    }

    /**
     * Satu tabel dokumen → peta journal_id ⇒ baris.
     *
     * Kolom diperiksa keberadaannya lebih dulu: tabel dokumen di aplikasi ini tumbuh lewat
     * migrasi yang berbeda-beda, dan menyebut kolom yang belum ada akan melempar SQLSTATE 42S22
     * yang menjatuhkan SELURUH layar — bukan sekadar menghilangkan satu penaut.
     *
     * @param  array<int,string>  $tambahan
     * @return array<int,object>
     */
    private function ambil(string $tabel, string $kolomJurnal, string $kolomNomor, array $tambahan, array $ids): array
    {
        if (! $this->punyaKolom($tabel, $kolomJurnal)) {
            return [];
        }

        $pilih = ['id', $kolomJurnal];
        if ($this->punyaKolom($tabel, $kolomNomor) && $kolomNomor !== 'id') {
            $pilih[] = $kolomNomor.' AS number';
        }
        foreach ($tambahan as $k) {
            if ($this->punyaKolom($tabel, $k)) {
                $pilih[] = $k;
            }
        }

        $q = DB::table($tabel)->whereIn($kolomJurnal, $ids);
        if ($this->punyaKolom($tabel, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $out = [];
        foreach ($q->get($pilih) as $r) {
            $out[(int) $r->{$kolomJurnal}] = $r;
        }

        return $out;
    }

    /** @var array<string,bool> */
    private array $kolomCache = [];

    private function punyaKolom(string $tabel, string $kolom): bool
    {
        $key = $tabel.'.'.$kolom;
        if (! array_key_exists($key, $this->kolomCache)) {
            // Sengaja TIDAK memakai Cache: hasil false yang terlanjur tersimpan karena satu
            // kegagalan sesaat akan mematikan penaut ini sampai cache dibersihkan manual.
            $this->kolomCache[$key] = Schema::hasTable($tabel) && Schema::hasColumn($tabel, $kolom);
        }

        return $this->kolomCache[$key];
    }
}
