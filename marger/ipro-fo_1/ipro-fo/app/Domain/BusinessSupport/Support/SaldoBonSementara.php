<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use App\Domain\BusinessSupport\Models\BsCashAdvance;

/**
 * LIMA ANGKA KARTU BON SEMENTARA — Kasbon Awal · Realisasi · Refund · Tidak Terpakai · Outstanding.
 *
 * Dulu Kartu Bon Sementara hanya punya tiga angka, dan outstanding-nya sekadar
 * `amount − realized_amount`. Rumus itu benar untuk bon reguler tetapi SALAH untuk bon Debit VA
 * model baru: yang benar-benar keluar dari bank hanyalah total Alokasi Kasbon, sedangkan sisanya
 * masih duduk sebagai saldo kartu. Bon yang uangnya sudah kembali seluruhnya pun tetap tampak
 * "outstanding" hanya karena tidak pernah dialokasikan penuh.
 *
 * Kelima angkanya kini dipisah supaya sisa itu punya nama:
 *
 *   Kasbon Awal    = nominal saat pengajuan (bs_cash_advances.amount).
 *   Realisasi      = nominal saat realisasi (realized_amount).
 *   Refund         = uang yang BENAR-BENAR kembali ke kas/bank. Diturunkan dari kolom
 *                    `settlement`, yang ditulis sekali saat realisasi sebagai (acuan − realisasi)
 *                    dan tidak pernah berubah lagi; refund/reimburse hanya menggeser
 *                    `settlement_status`. Karena itu refund tidak pernah sebagian — selalu penuh.
 *                    BERTANDA: (+) kembali ke kas, (−) perusahaan menombok (reimburse).
 *   Tidak Terpakai = Kasbon Awal − Realisasi − Refund, dan hanya untuk bon yang sudah TUNTAS.
 *                    Pada bon Debit VA ia persis sebesar bagian yang tidak pernah dialokasikan
 *                    (amount − total alokasi) — uang yang tidak pernah meninggalkan kartu.
 *                    Pada bon reguler ia selalu 0: seluruh uangnya memang sudah keluar.
 *   Outstanding    = Kasbon Awal − Realisasi − Refund − Tidak Terpakai. Nilai tidak terpakai
 *                    ditentukan LEBIH DULU, sehingga bon yang sudah tuntas berakhir di 0 dan yang
 *                    tersisa benar-benar hanya yang masih ditanggung pemegangnya.
 *
 * KENAPA "TUNTAS" JADI SYARAT. Tanpa syarat itu, rumusnya membuat outstanding selalu 0 — bon yang
 * belum direalisasi sekalipun akan terlihat lunas, sebab seluruh nilainya terserap menjadi "tidak
 * terpakai". Yang belum tuntas justru masih ditanggung pemegang bon, jadi sisanya harus tetap
 * berdiri sebagai outstanding.
 */
final class SaldoBonSementara
{
    /**
     * Status penyelesaian yang berarti "sudah tuntas".
     *
     * 'settled' → sisanya sudah kembali ke kas lewat jurnal Dr Kas.
     * 'card'    → sisanya dikembalikan ke saldo kartu (Masuk Saldo), tanpa jurnal: uangnya memang
     *             tidak pernah kembali ke bank, tetapi juga tidak lagi ditanggung pemegang bon.
     *             Karena itu ia bukan Refund, melainkan Tidak Terpakai.
     */
    public const TUNTAS = ['settled', 'card'];

    /**
     * @return array{kasbon_awal:float, realisasi:float, refund:float, tidak_terpakai:float, outstanding:float}
     */
    public static function dariBon(BsCashAdvance $bon): array
    {
        return self::hitung(
            (float) $bon->amount,
            (float) $bon->realized_amount,
            (float) $bon->settlement,
            $bon->settlement_status,
        );
    }

    /**
     * Bentuk murni — tanpa model, supaya aturannya bisa diuji apa adanya.
     *
     * @return array{kasbon_awal:float, realisasi:float, refund:float, tidak_terpakai:float, outstanding:float}
     */
    public static function hitung(
        float $amount,
        float $realized,
        float $settlement,
        ?string $settlementStatus,
    ): array {
        $awal      = round($amount, 2);
        $realisasi = round($realized, 2);

        /* Refund hanya diakui bila uangnya BENAR-BENAR sudah kembali ke kas. 'pending' berarti
           penyelesaiannya masih ditunda — mengakuinya sekarang menihilkan outstanding atas uang
           yang masih dipegang orang. 'card' bukan refund: uangnya kembali ke kartu, bukan ke kas. */
        $refund = $settlementStatus === 'settled' ? round($settlement, 2) : 0.0;

        $tuntas = in_array((string) $settlementStatus, self::TUNTAS, true);

        /* Sengaja TIDAK di-clamp ke 0. Nilai negatif di sini hanya mungkin lahir dari data yang
           sudah tidak konsisten (alokasi melebihi nominal bon); menyembunyikannya membuat
           selisihnya diam-diam pindah ke Outstanding tanpa satu pun tanda. */
        $tidakTerpakai = $tuntas ? round($awal - $realisasi - $refund, 2) : 0.0;

        return [
            'kasbon_awal'    => $awal,
            'realisasi'      => $realisasi,
            'refund'         => $refund,
            'tidak_terpakai' => $tidakTerpakai,
            'outstanding'    => round($awal - $realisasi - $refund - $tidakTerpakai, 2),
        ];
    }
}
