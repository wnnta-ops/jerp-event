<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Penyaring "Hutang Usaha (AP) / Biaya yang Masih Harus Dibayar (BYMHD) / Tampilkan Semua"
 * untuk SELURUH layar Query di modul Account Payable.
 *
 * ── Kenapa satu berkas untuk delapan layar ──
 * Kedelapan layar Query AP menghitung angkanya masing-masing (computeAging, computeCard,
 * paymentCardData, report/export, penghapusan, proyeksi). Bila tiap layar menuliskan
 * penyaringnya sendiri, dua layar yang menjanjikan angka yang sama — Aging Hutang dan Aging
 * Hutang Supplier bahkan menuliskan janji itu sebagai komentar — bisa perlahan menyaring hal
 * yang berbeda, dan tidak ada galat apa pun yang akan memberi tahu siapa pun.
 *
 * ── Kenapa kuncinya kategori, BUKAN akun pada header invoice ──
 * Godaan terbesarnya adalah menyaring lewat bs_ap_invoices.payable_account_id: kolom itu NOT
 * NULL, ada di setiap invoice, dan tidak butuh join. Itu salah, dan salahnya tidak kelihatan:
 *  - SELURUH kategori yang ada hari ini menunjuk akun yang SAMA (20101010101), dan seluruh
 *    jalur Posting & Saldo Awal juga memakai config gl_accounts.payable yang sama. Penyaring
 *    berbasis akun karena itu memulangkan "BYMHD selalu kosong, Hutang Usaha selalu semuanya"
 *    — tampak bekerja, tanpa satu pun galat, dan tidak berarti apa-apa.
 *  - Untuk posisi 'hutang', akunnya BEBAS dipilih operator (ApCategoryController::akunKredit
 *    hanya memaksa akun untuk posisi 'beban_ymhd'). Jadi akun tidak bisa dibalik menjadi
 *    posisi: kategori berposisi 'hutang' yang sengaja memakai akun kewajiban lain akan salah
 *    dilabeli selamanya.
 * Sumber kebenarannya adalah posisi kredit yang dipilih user — sejak 14 Okt 2026 dibekukan
 * per invoice di bs_ap_invoices.credit_position (satu kategori boleh dipakai beberapa modul
 * dengan posisi berbeda). Invoice sebelum itu (kolomnya kosong) tetap dibaca dari
 * bs_ap_categories.credit_position.
 *
 * ── Kenapa akun tetap dipakai, tapi HANYA sebagai cadangan ──
 * bs_ap_invoices.category_id BOLEH KOSONG, dan memang kosong pada seluruh invoice Saldo Awal
 * dan seluruh invoice hasil Posting (satu-satunya jalur yang mengisinya adalah Registrasi
 * Invoice Hutang manual). Itu hutang sungguhan. Bila invoice tanpa kategori dibuang dari kedua
 * pilihan, hutang nyata menghilang dari Aging, Kartu Hutang, dan Proyeksi begitu operator
 * memilih salah satu posisi — kehilangan yang tidak terbaca sebagai kesalahan, hanya sebagai
 * angka yang lebih kecil. Untuk invoice tanpa kategori, posisinya dibaca dari akun yang
 * BENAR-BENAR DIKREDIT jurnalnya (payable_account_id, NOT NULL): sama dengan akun Beban Masih
 * Harus Dibayar berarti BYMHD, selain itu Hutang Usaha. Ini bukan tebakan — itu yang tercatat
 * di jurnalnya.
 *
 * ── Kenapa satu predikat lalu dinegasikan ──
 * "Hutang Usaha" TIDAK ditulis sebagai predikat kedua yang berdiri sendiri, melainkan sebagai
 * NOT dari predikat BYMHD. Dua predikat terpisah bisa sama-sama meleset pada baris yang sama
 * (mis. category_id menunjuk kategori yang sudah dihapus) sehingga barisnya hilang dari kedua
 * pilihan sekaligus, dan "Tampilkan Semua" tidak lagi sama dengan jumlah keduanya. Dengan
 * negasi, sifat itu dijamin secara aljabar, bukan dengan kehati-hatian:
 *
 *      jumlah(Tampilkan Semua) === jumlah(Hutang Usaha) + jumlah(BYMHD)
 *
 * Sifat itu yang diuji tests/Manual/uji-posisi-hutang-query-ap.php.
 */
final class PosisiHutang
{
    /** Nama parameter URL — sama di kedelapan layar supaya pilihan tidak hilang saat berpindah. */
    public const KUNCI = 'posisi';

    public const SEMUA = 'semua';

    /** Sama persis dengan BsApCategory::POSISI_HUTANG — dipakai sebagai nilai URL. */
    public const HUTANG = BsApCategory::POSISI_HUTANG;

    /** Sama persis dengan BsApCategory::POSISI_BEBAN. */
    public const BEBAN = BsApCategory::POSISI_BEBAN;

    /**
     * Label yang dibaca operator. Dipakai layar (dropdown) DAN judul berkas ekspor, supaya
     * cetakan selalu menyebutkan posisi mana yang sedang dilihat.
     */
    public const PILIHAN = [
        self::SEMUA  => 'Tampilkan Semua',
        self::HUTANG => 'Hutang Usaha (AP)',
        self::BEBAN  => 'Biaya yang Masih Harus Dibayar (BYMHD)',
    ];

    /**
     * Bawaannya SEMUA, dan itu keputusan yang disengaja: seluruh laporan AP yang selama ini
     * dicetak operator dihitung tanpa penyaring ini. Menjadikan "Hutang Usaha" sebagai bawaan
     * akan mengubah angka pada setiap layar tanpa ada yang memintanya, dan perubahan itu baru
     * ketahuan saat seseorang membandingkan cetakan lama dengan yang baru.
     */
    public static function sah(?string $nilai): string
    {
        $v = strtolower(trim((string) $nilai));

        return isset(self::PILIHAN[$v]) ? $v : self::SEMUA;
    }

    public static function dariRequest(Request $request): string
    {
        return self::sah($request->input(self::KUNCI));
    }

    public static function label(?string $nilai): string
    {
        return self::PILIHAN[self::sah($nilai)];
    }

    /** Daftar pilihan untuk dropdown di layar Inertia. */
    public static function opsi(): array
    {
        return collect(self::PILIHAN)->map(fn ($label, $nilai) => ['nilai' => $nilai, 'label' => $label])->values()->all();
    }

    /**
     * Saring query yang BARISNYA INVOICE (BsApInvoice).
     *
     * @param  Builder<\App\Domain\BusinessSupport\Models\BsApInvoice>  $q
     */
    public static function saring(Builder $q, ?string $posisi): Builder
    {
        $p = self::sah($posisi);

        if ($p === self::SEMUA) {
            return $q;
        }

        return $p === self::BEBAN
            ? $q->where(fn ($w) => self::predikatBeban($w))
            : $q->whereNot(fn ($w) => self::predikatBeban($w));
    }

    /**
     * Saring query yang barisnya MENUNJUK invoice lewat sebuah relasi (pembayaran, memorial).
     *
     * Pada "Tampilkan Semua" query dikembalikan UTUH — bukan whereHas yang selalu benar.
     * Bedanya menentukan: relasi seperti bs_ap_memorials.ap_invoice_id boleh kosong, dan
     * whereHas akan membuang baris tanpa invoice bahkan ketika operator tidak menyaring apa pun.
     */
    public static function saringLewat(Builder $q, ?string $posisi, string $relasi): Builder
    {
        $p = self::sah($posisi);

        if ($p === self::SEMUA) {
            return $q;
        }

        return $q->whereHas($relasi, fn ($i) => self::saring($i, $p));
    }

    /**
     * "Invoice ini BYMHD" — satu-satunya predikat yang ditulis; lawannya adalah negasinya.
     *
     * @param  Builder<\App\Domain\BusinessSupport\Models\BsApInvoice>  $w
     */
    private static function predikatBeban(Builder $w): Builder
    {
        return $w
            // Invoice yang posisinya dibekukan saat disimpan (sejak satu kategori boleh dipakai
            // beberapa modul dengan posisi berbeda, posisi kategori tidak lagi cukup).
            // IS NOT NULL ikut ditulis: tanpanya `credit_position = 'beban_ymhd'` bernilai NULL
            // pada invoice lama, NOT-nya juga NULL, dan invoice lama hilang dari "Hutang Usaha".
            ->where(fn ($b) => $b->whereNotNull('credit_position')->where('credit_position', self::BEBAN))
            // Invoice lama tanpa posisi beku: cara baca lama, apa adanya.
            ->orWhere(fn ($lama) => $lama->whereNull('credit_position')->where(fn ($l) => $l
                ->whereHas('category', fn ($c) => $c->where('credit_position', self::BEBAN))
                // whereDoesntHave, bukan whereNull('category_id'): category_id yang menunjuk
                // kategori terhapus juga tidak punya posisi, dan barisnya tetap harus mendarat di
                // salah satu pilihan.
                ->orWhere(fn ($x) => $x->whereDoesntHave('category')->where('payable_account_id', self::akunBebanId()))));
    }

    /**
     * id akun Beban Masih Harus Dibayar (kode dari config, bukan id mati).
     *
     * Memulangkan 0 bila akunnya belum ada di COA — bukan null: `where($kolom, null)` diubah
     * Laravel menjadi `IS NULL`, dan payable_account_id NOT NULL sehingga syaratnya akan selalu
     * salah dengan cara yang tidak terbaca. 0 tidak pernah cocok dengan id mana pun, dan itu
     * memang jawaban yang benar ketika akunnya belum dikonfigurasi.
     */
    private static function akunBebanId(): int
    {
        static $id = null;

        if ($id === null) {
            $kode = (string) config('business_support.gl_accounts.accrued_expense');
            $id = (int) (BsAccount::where('code', $kode)->value('id') ?? 0);
        }

        return $id;
    }
}
