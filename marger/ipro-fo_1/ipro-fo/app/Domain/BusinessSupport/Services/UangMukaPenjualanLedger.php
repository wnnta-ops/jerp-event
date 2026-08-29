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
     * Penelusuran balik jurnal → dokumen dikeluarkan ke BukuBesarDokumen: caranya identik
     * untuk akun mana pun (Rekap PPh memakainya juga), dan menyalinnya berarti tiap salinan
     * punya daftar tabel dokumennya sendiri yang lambat laun berbeda isinya.
     */
    public function __construct(private readonly BukuBesarDokumen $bb) {}

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
     * Didelegasikan ke BukuBesarDokumen — penelusurannya identik untuk akun mana pun; yang
     * khas di sini hanya PEMILIHAN akunnya.
     *
     * @return Collection<int,BsJournalLine>
     */
    public function lines(?string $from = null, ?string $to = null): Collection
    {
        return $this->bb->lines($this->accountIds(), $from, $to);
    }

    /**
     * Dokumen sumber di balik tiap jurnal.
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,array<string,mixed>>
     */
    public function documentsFor(array $journalIds): array
    {
        return $this->bb->documentsFor($journalIds);
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
     * Dipakai layar UM Penjualan untuk menyandingkan nilai baris antreannya dengan nilai yang
     * BENAR-BENAR masuk buku besar. Keduanya kerap berbeda dan bukan karena kekeliruan: baris
     * antrean bernilai TOTAL termasuk PPN, sedangkan yang masuk akun UM hanya DPP-nya
     * (PPN-nya duduk di akun PPN Keluaran).
     *
     * @param  array<int,int>  $journalIds
     * @return array<int,float>
     */
    public function netByJournal(array $journalIds): array
    {
        return $this->bb->netByJournal($journalIds, $this->accountIds());
    }

    /** Kolom penaut dokumen yang tidak ada di basis data — lihat BukuBesarDokumen. */
    public function kolomHilang(): array
    {
        return $this->bb->kolomHilang();
    }
}
