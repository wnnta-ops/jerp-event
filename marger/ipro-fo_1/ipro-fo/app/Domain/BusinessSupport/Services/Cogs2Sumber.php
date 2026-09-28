<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogs2Akun;
use App\Domain\BusinessSupport\Models\BsCogs2Kategori;
use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use Illuminate\Support\Collection;

/**
 * Dua sumber otomatis untuk Actual Costing COGS, dan SATU tempat yang memetakan kosakata
 * komponennya.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *  KOSAKATA: dua penamaan untuk empat hal yang sama
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * Chart of Account dan Komponen COGS (modul lama) memakai KUNCI HPP:
 *     tanah · bangunan · sarana · permit
 * COGS Baru memakai kunci sendiri, mengikuti nama lembar di lampiran:
 *     tanah · housing · infra · license
 *
 * Pemetaannya HANYA ada di sini. Dua kosakata untuk satu hal adalah sumber kesalahan yang sudah
 * berulang di modul ini, dan satu terjemahan yang terlupa menghasilkan komponen yang diam-diam
 * kosong — bukan galat. Siapa pun yang butuh menyeberang, menyeberang lewat kelas ini.
 */
class Cogs2Sumber
{
    /** Kunci HPP (Chart of Account / Komponen COGS lama) → kunci COGS Baru. */
    public const DARI_HPP = [
        'tanah'    => 'tanah',
        'bangunan' => 'housing',
        'sarana'   => 'infra',
        'permit'   => 'license',
    ];

    /**
     * Komponen yang BERKOLOM KATEGORI di Actual Costing COGS.
     *
     * Tanah tidak: di lampiran, lembar Actual Costing COGS-TANAH memang tidak punya kolom
     * kategori. Realisasi Housing dicocokkan lewat (nomor SPK + kategori), jadi tautan tanpa
     * kategori tidak akan pernah cocok dengan baris Set Up Kategori mana pun dan nilainya
     * berhenti di nol tanpa keterangan.
     */
    public const PERLU_KATEGORI = ['housing', 'infra', 'license'];

    /** Kunci COGS Baru → kunci HPP. */
    public static function keHpp(string $cogs2): ?string
    {
        $balik = array_flip(self::DARI_HPP);

        return $balik[$cogs2] ?? null;
    }

    public static function dariHpp(?string $hpp): ?string
    {
        return $hpp === null ? null : (self::DARI_HPP[$hpp] ?? null);
    }

    public static function perluKategori(string $cogs2): bool
    {
        return in_array($cogs2, self::PERLU_KATEGORI, true);
    }

    /**
     * Pilihan kategori yang tampil di modal "Link COGS" pada Kontrak SPK (MUK).
     *
     * Diambil dari Set Up Kategori COGS SELURUH periode, digabung dan dibuat unik menurut nama:
     * satu kontrak MUK tidak membawa periode, jadi mempersempitnya ke satu periode akan
     * menyembunyikan kategori yang sah hanya karena operator sedang membuka periode lain.
     *
     * @return array<string, list<string>>  kunci COGS Baru → daftar nama kategori
     */
    public static function pilihanKategori(): array
    {
        $out = [];
        foreach (self::PERLU_KATEGORI as $k) {
            $out[$k] = BsCogs2Kategori::where('komponen', $k)
                ->whereNotNull('kategori')
                ->pluck('kategori')
                ->map(fn ($v) => trim((string) $v))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        }
        // Tanah tidak berkategori — dipulangkan kosong supaya layar tidak perlu tahu aturannya.
        $out['tanah'] = [];

        return $out;
    }

    /**
     * Kontrak SPK (MUK) yang ditautkan, sebagai baris Actual Costing.
     *
     * ── Yang dibebankan adalah NILAI KONTRAK, bukan yang sudah ditagih ──
     * Tujuan layar ini adalah mengetahui KESELURUHAN COGS/HPP sebuah komponen supaya bisa
     * dibebankan ke tiap unit dengan tepat. Nilai kontrak adalah angka yang utuh itu; yang sudah
     * ditagih baru sebagian dan bertambah sendiri seiring penagihan berjalan.
     *
     * Memakai yang sudah ditagih membuat HPP per unit BERGERAK setiap kali ada tagihan baru,
     * padahal biaya kontraknya sejak awal sudah pasti — unit yang di-booked lebih dulu akan
     * terkunci pada angka yang lebih kecil daripada unit yang di-booked belakangan, semata-mata
     * karena waktu penagihan. Dengan nilai kontrak, pembebanannya stabil sejak hari pertama.
     *
     * Konsekuensinya disebut apa adanya, bukan disembunyikan: selama kontraknya belum selesai
     * ditagih, angka di sini LEBIH BESAR daripada kas yang sudah keluar. Karena itu "Sudah
     * Ditagih" dan "Diopname" tetap ditampilkan berdampingan — dari keduanya terbaca seberapa
     * jauh kontraknya sudah berjalan — tetapi tidak ikut dijumlahkan.
     *
     * @return Collection<int, object>
     */
    public function muk(BsCogs2Periode $periode): Collection
    {
        $tautan = BsMukContractCogs::query()->get()->keyBy('muk_id');
        if ($tautan->isEmpty()) {
            return collect();
        }

        $kontrak = BsMukContract::whereIn('muk_id', $tautan->keys()->all())->get();

        return $kontrak->map(function (BsMukContract $k) use ($tautan, $periode) {
            $t = $tautan[(int) $k->muk_id] ?? null;
            $komponen = self::dariHpp($t?->component);
            if ($komponen === null) {
                return null;
            }

            return (object) [
                'sumber'      => 'muk',
                'komponen'    => $komponen,
                'periode_id'  => $periode->id,
                'lokasi'      => $k->proyek_kode,
                'tahap'       => null,
                'kategori'    => $t?->kategori,
                'no_spk'      => $k->no_spk,
                'no_jurnal'   => null,
                'no_kontrak'  => $k->no_kontrak,
                'proyek'      => trim((string) (($k->proyek_kode ? $k->proyek_kode.' — ' : '').$k->proyek_nama)),
                'keterangan'  => 'Kontrak SPK (MUK) '.$k->no_kontrak,
                // Yang dibebankan adalah NILAI KONTRAK — lihat alasannya di docblock method ini.
                'penambahan'  => round((float) $k->nilai_kontrak, 2),
                'pengurangan' => 0.0,
                // Ditampilkan berdampingan sebagai keterangan, tidak ikut dijumlahkan: dari
                // keduanya terbaca seberapa jauh kontraknya sudah berjalan.
                'ditagih'     => round((float) $k->kum_ditagih_neto, 2),
                'diopname'    => round((float) $k->kum_diopname, 2),
                'efektif'     => true,
            ];
        })->filter()->values();
    }

    /**
     * Mutasi buku besar pada akun COA yang DITANDAI komponen dan DIAKUI milik periode ini.
     *
     * ── Masuk & keluar tetap dipisah ──
     * Akun CIP yang ditandai komponen tidak hanya didebit oleh biaya; ia juga DIKREDIT oleh
     * proses HPP itu sendiri. Debit menjadi penambahan, kredit menjadi pengurangan, dan saldo
     * akhirnya yang dipakai — sama persis dengan bentuk baris Actual Costing yang diketik tangan.
     *
     * ── Hanya jurnal yang sudah efektif yang DIJUMLAHKAN ──
     * Status bawaan pemasangan ini 'final', bukan 'posted', sedangkan Buku Besar & Neraca hanya
     * menghitung 'posted'. Menyaring 'posted' saja membuat layar kosong padahal biayanya ada;
     * menjumlahkan semuanya membuat angkanya tidak pernah sama dengan Buku Besar. Jadi seluruh
     * barisnya DITAMPILKAN dengan penanda, dan hanya yang efektif yang ikut dijumlahkan —
     * pola yang sama dengan layar Komponen COGS.
     *
     * @return Collection<int, object>
     */
    public function gl(BsCogs2Periode $periode): Collection
    {
        $akunId = BsCogs2Akun::where('periode_id', $periode->id)->pluck('account_id')->all();
        if ($akunId === []) {
            return collect();
        }

        $akun = BsAccount::whereIn('id', $akunId)->whereNotNull('cogs_component')->get()->keyBy('id');
        if ($akun->isEmpty()) {
            return collect();
        }

        $baris = BsJournalLine::whereIn('account_id', $akun->keys()->all())
            ->with(['journal:id,journal_number,journal_date,status,description'])
            ->get();

        return $baris->map(function (BsJournalLine $l) use ($akun, $periode) {
            $a = $akun[$l->account_id] ?? null;
            $komponen = self::dariHpp($a?->cogs_component);
            if ($komponen === null) {
                return null;
            }
            $efektif = (string) ($l->journal->status ?? '') === 'posted';

            return (object) [
                'sumber'      => 'gl',
                'komponen'    => $komponen,
                'periode_id'  => $periode->id,
                'lokasi'      => null,
                'tahap'       => null,
                'kategori'    => null,
                'no_spk'      => null,
                'no_jurnal'   => $l->journal->journal_number ?? null,
                'tanggal'     => $l->journal?->journal_date ? substr((string) $l->journal->journal_date, 0, 10) : null,
                'akun'        => trim((string) $a->code.' — '.$a->name),
                'keterangan'  => $l->description ?: ($l->journal->description ?? null),
                'status'      => $l->journal->status ?? null,
                // Baris yang belum efektif tetap TAMPIL tetapi tidak ikut dijumlahkan.
                'penambahan'  => $efektif ? round((float) $l->debit, 2) : 0.0,
                'pengurangan' => $efektif ? round((float) $l->credit, 2) : 0.0,
                'debit'       => round((float) $l->debit, 2),
                'kredit'      => round((float) $l->credit, 2),
                'efektif'     => $efektif,
            ];
        })->filter()->values();
    }

    /**
     * Akun COA bertanda komponen, beserta keterangan apakah sudah diakui milik periode ini.
     *
     * @return list<array<string,mixed>>
     */
    public function akunBertanda(BsCogs2Periode $periode, string $komponen): array
    {
        $hpp = self::keHpp($komponen);
        if ($hpp === null) {
            return [];
        }

        $dipakai = BsCogs2Akun::where('periode_id', $periode->id)->pluck('account_id')->all();
        // Akun yang sudah diakui periode LAIN ditandai, bukan disembunyikan: menyembunyikannya
        // membuat operator mengira akunnya belum ditandai komponen sama sekali.
        $milikLain = BsCogs2Akun::where('periode_id', '!=', $periode->id)
            ->pluck('periode_id', 'account_id')->all();

        return BsAccount::where('cogs_component', $hpp)->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (BsAccount $a) => [
                'id'        => $a->id,
                'code'      => $a->code,
                'name'      => $a->name,
                'dipakai'   => in_array($a->id, $dipakai, true),
                'milikLain' => array_key_exists($a->id, $milikLain) ? (int) $milikLain[$a->id] : null,
            ])->values()->all();
    }
}
