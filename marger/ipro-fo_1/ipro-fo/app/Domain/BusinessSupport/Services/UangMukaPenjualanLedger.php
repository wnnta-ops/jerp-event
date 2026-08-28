<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buku besar akun Uang Muka Penjualan — jembatan antara Detail Transaksi Jurnal dan layar
 * UM Penjualan (Piutang Usaha (Booked)).
 *
 * KENAPA ADA: layar UM Penjualan membangun barisnya dari TIGA tabel operasional
 * (kpr_retentions, kpr_realizations, bs_faktur_keluaran_entries), sementara akun
 * 20106060601 di buku besar ditulis oleh JAUH LEBIH BANYAK jalur — Booked Revenue,
 * top-up kas kecil, dan jurnal memorial manual termasuk di dalamnya. Akibatnya sebuah
 * baris bisa ada di buku besar tetapi tidak pernah muncul di layar UM Penjualan, dan
 * tidak ada satu pun galat yang memberitahu: layarnya hanya tampak "memang segitu".
 *
 * Kelas ini menempuh arah SEBALIKNYA — dari baris jurnal kembali ke dokumen yang
 * melahirkannya — mengikuti pola yang sudah dipakai JournalPartyResolver: satu query
 * per tabel dokumen (whereIn journal_id), bukan N+1 per jurnal. Alasannya sama: kaitan
 * di basis data ini memang terbalik, belasan tabel dokumen menyimpan journal_id yang
 * menunjuk ke bs_journals.id, sedangkan bs_journal_lines tidak punya satu pun kolom
 * penaut ke dokumen sumbernya (sudah diverifikasi ke skema).
 *
 * @see JournalPartyResolver pola penelusuran balik yang sama
 */
final class UangMukaPenjualanLedger
{
    /**
     * Batas id per putaran, disamakan dengan JournalPartyResolver — menjaga jumlah
     * placeholder jauh di bawah batas MySQL walau operator menarik rentang bertahun-tahun.
     */
    private const CHUNK = 5000;

    /**
     * Akun yang dianggap "Uang Muka Penjualan".
     *
     * Dicari lewat NAMA lebih dulu, persis seperti yang dilakukan jalur-jalur yang
     * MEMPOSTING ke akun ini (ConvertUtjDpToUangMuka::resolveAccountId dan
     * BankApprovalController::retensiAccountDefaults). Kalau layar ini memakai aturan
     * pencarian yang berbeda dari yang memposting, keduanya bisa menunjuk akun berlainan
     * dan selisihnya mustahil dijelaskan.
     *
     * Dikembalikan LEBIH DARI SATU bila memang ada lebih dari satu — permintaannya
     * "semua akun uang muka penjualan", bukan satu akun tertentu.
     *
     * @return Collection<int,BsAccount>
     */
    public function accounts(): Collection
    {
        $postable = BsAccount::query()
            ->where('is_active', true)->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'normal_balance']);

        $cocok = $postable->filter(
            fn (BsAccount $a) => str_contains(mb_strtolower((string) $a->name), 'uang muka penjualan')
        )->values();

        if ($cocok->isNotEmpty()) {
            return $cocok;
        }

        // Tidak ada yang cocok namanya → jatuh ke kode di config, sama seperti jalur posting.
        $code = config('business_support.gl_accounts.unearned_revenue');
        $byCode = $code ? $postable->firstWhere('code', $code) : null;

        return $byCode ? collect([$byCode]) : collect();
    }

    /** @return array<int,int> */
    public function accountIds(): array
    {
        return $this->accounts()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Baris jurnal pada akun Uang Muka Penjualan.
     *
     * Jurnal ber-status apa pun ikut (Baru/Final/Approved), karena Detail Transaksi Jurnal
     * juga menampilkan semuanya — kalau di sini disaring, rincian kedua layar tidak akan
     * pernah sama dan justru itu yang sedang dibereskan. Statusnya dibawa per baris supaya
     * yang belum efektif tetap bisa dibedakan.
     *
     * @return Collection<int,BsJournalLine>
     */
    public function lines(?string $from = null, ?string $to = null): Collection
    {
        $ids = $this->accountIds();
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
     * Dokumen sumber di balik tiap jurnal.
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,array{type:string,label:string,id:int,number:?string,customer_id:?int,booking_id:?int}>
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
     * Peta jurnal → tiga sumber yang SUDAH diwakili layar UM Penjualan.
     *
     * Dipakai untuk membuang baris buku besar yang dokumennya sudah punya barisnya sendiri
     * di layar — tanpa ini satu penerimaan tampil dua kali dan totalnya berlipat.
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,string>  journal_id => row_key ("retensi-12", "pencairan-3", "utj_dp-8")
     */
    public function claimedByQueue(array $journalIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $isi = function (string $tabel, string $kolom, string $jenis, ?callable $saring = null) use ($ids, &$out): void {
            $q = DB::table($tabel)->whereIn($kolom, $ids);
            if ($saring) {
                $saring($q);
            }
            foreach ($q->pluck('id', $kolom) as $journalId => $rowId) {
                // Yang ketemu duluan menang — sebuah jurnal hanya boleh diklaim satu baris antrean.
                $out[(int) $journalId] ??= $jenis.'-'.$rowId;
            }
        };

        // Saringannya WAJIB sama persis dengan yang membentuk antrean di ArUmPenjualanController
        // (retensiRows/pencairanRows/utjDpRows). Kalau lebih longgar, sebuah baris buku besar
        // akan ditandai "ada di antrean" padahal tidak ada — operator mencarinya di tab Antrean,
        // tidak menemukannya, dan tandanya berbohong tanpa satu pun galat.
        $isi('kpr_retentions', 'journal_id', 'retensi');
        $isi('kpr_realizations', 'journal_id', 'pencairan',
            fn ($q) => $q->whereRaw('(disbursed_amount - COALESCE(retention_amount, 0)) > 0.009'));
        // Entri UTJ/DP punya DUA jurnal: jurnal approval awal (journal_id) dan jurnal konversi
        // ke Uang Muka (convert_journal_id). HANYA yang konversi yang diklaim: jurnal approval
        // bisa saja menyentuh akun UM (akun kreditnya dipilih operator) tanpa entrinya pernah
        // dikonversi — dan selama converted_at kosong, entri itu TIDAK pernah masuk antrean.
        $isi('bs_faktur_keluaran_entries', 'convert_journal_id', 'utj_dp',
            fn ($q) => $q->where('source_type', 'utj_dp')->whereNotNull('converted_at'));

        return $out;
    }

    /**
     * Nilai bersih akun UM per jurnal (kredit − debit).
     *
     * Dipakai layar UM Penjualan untuk menyandingkan nilai baris antreannya dengan nilai
     * yang BENAR-BENAR masuk buku besar. Keduanya kerap berbeda dan bukan karena kekeliruan:
     * baris antrean bernilai TOTAL termasuk PPN, sedangkan yang masuk akun UM hanya DPP-nya
     * (PPN-nya duduk di akun PPN Keluaran).
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,float>
     */
    public function netByJournal(array $journalIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        $akun = $this->accountIds();
        if ($ids === [] || $akun === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $part) {
            $rows = DB::table('bs_journal_lines')
                ->whereIn('journal_id', $part)->whereIn('account_id', $akun)
                ->groupBy('journal_id')
                ->get([
                    'journal_id',
                    DB::raw('SUM(credit) AS c'),
                    DB::raw('SUM(debit) AS d'),
                ]);
            foreach ($rows as $r) {
                $out[(int) $r->journal_id] = round((float) $r->c - (float) $r->d, 2);
            }
        }

        return $out;
    }

    // ── Penelusuran balik jurnal → dokumen ───────────────────────────────────────────────

    /**
     * Tabel dokumen yang menyimpan journal_id, urut dari yang paling spesifik.
     * Bentuk: [tabel, kolom jurnal, kolom nomor, kolom tambahan, jenis, label].
     *
     * @var array<int,array{0:string,1:array<int,string>,2:string,3:array<int,string>,4:string,5:string}>
     */
    private const DOKUMEN = [
        // Booked Revenue — jalur terbesar yang selama ini tak terwakili di layar UM Penjualan.
        // Tiga kolom jurnal: booking (saat BR dibuat), pengakuan, dan pembatalan.
        ['bs_booked_revenues', ['booking_journal_id', 'recognition_journal_id', 'cancel_journal_id'], 'br_number', ['customer_id'], 'booked_revenue', 'Booked Revenue'],
        ['bs_ar_invoices', ['journal_id'], 'invoice_number', ['customer_id', 'booking_id'], 'ar_invoice', 'Invoice Piutang'],
        ['bs_ar_kuitansis', ['journal_id'], 'kuitansi_number', ['customer_id'], 'ar_kuitansi', 'Kuitansi Piutang'],
        ['bs_ar_memorials', ['journal_id'], 'memorial_number', ['customer_id'], 'ar_memorial', 'Memorial Piutang'],
        // Entri faktur keluaran: convert_journal_id (konversi ke Uang Muka) DAN journal_id
        // (approval tahap-1, yang akun kreditnya dipilih operator dan bisa saja akun UM).
        ['bs_faktur_keluaran_entries', ['convert_journal_id', 'journal_id'], 'fp_number', ['customer_id', 'booking_id'], 'faktur_keluaran', 'Entri Faktur Keluaran'],
        ['tts_receipts', ['journal_id'], 'tts_number', ['customer_id', 'booking_id'], 'tts', 'TTS (UTJ/DP)'],
        // Pelepasan retensi DAJAM per tranche — memposting ke akun UM tetapi BUKAN salah satu
        // dari tiga sumber antrean, jadi tanpa baris ini ia selalu terbaca "tak terlacak".
        // Tabelnya tidak punya kolom nomor; id-nya yang dipakai sebagai penanda.
        ['kpr_retention_releases', ['journal_id'], 'id', [], 'retensi_release', 'Pelepasan Retensi DAJAM'],
        ['kpr_retentions', ['journal_id'], 'id', [], 'retensi', 'Retensi DAJAM'],
        ['kpr_realizations', ['journal_id'], 'id', [], 'pencairan', 'Pencairan KPR'],
        ['bs_giros', ['receive_journal_id', 'settle_journal_id'], 'giro_number', [], 'giro', 'Giro'],
        ['bs_ap_invoices', ['journal_id'], 'invoice_number', ['customer_id'], 'ap_invoice', 'Invoice Hutang'],
        ['bs_cash_transactions', ['journal_id'], 'trx_number', [], 'cash', 'Transaksi Kas/Bank'],
    ];

    /**
     * Kolom yang disebut DOKUMEN tetapi tidak ada di basis data.
     *
     * Penelusuran balik melewati kolom yang tidak ada supaya satu tabel yang belum
     * dimigrasi tidak menjatuhkan seluruh layar. Sifat memaafkan itu ada harganya: salah
     * ketik nama kolom juga ikut dimaafkan, dan penautnya diam-diam mati — barisnya tetap
     * tampil, hanya kehilangan nomor dokumen & customernya, tanpa satu pun galat.
     * Metode ini membuat kekeliruan itu bisa diperiksa oleh uji, bukan hanya oleh mata.
     *
     * @return array<int,string>
     */
    public function kolomHilang(): array
    {
        $hilang = [];
        foreach (self::DOKUMEN as [$tabel, $kolomJurnal, $kolomNomor, $tambahan]) {
            foreach ([...$kolomJurnal, $kolomNomor, ...$tambahan] as $k) {
                if (! $this->punyaKolom($tabel, $k)) {
                    $hilang[] = $tabel.'.'.$k;
                }
            }
        }

        return $hilang;
    }

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
     * migrasi yang berbeda-beda, dan menyebut kolom yang belum ada akan melempar SQLSTATE
     * 42S22 yang menjatuhkan SELURUH layar UM Penjualan — bukan sekadar menghilangkan satu
     * penaut. Ketiadaan kolom di sini memang wajar, jadi ia dilewati, bukan digagalkan.
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
        if ($this->punyaKolom($tabel, $kolomNomor)) {
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
            $this->kolomCache[$key] = \Illuminate\Support\Facades\Schema::hasColumn($tabel, $kolom);
        }

        return $this->kolomCache[$key];
    }
}
