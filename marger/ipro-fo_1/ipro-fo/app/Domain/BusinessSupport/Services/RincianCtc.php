<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

/**
 * Rincian satu SPK untuk layar Informasi CTC (Lampiran 1).
 *
 * ── ARITMETIKANYA, DAN KENAPA BEGITU ──
 *
 *   Sisa Pembayaran = Nilai SPK (Tagihan) − seluruh pembayaran
 *   Sudah Tertagih  = bagian dari sisa itu yang COP/tagihannya SUDAH diterbitkan MUK
 *   Belum Tertagih  = Sisa Pembayaran − Sudah Tertagih
 *
 * Contoh dari pemilik proses, dan angka ini yang dipakai menyetel rumusnya:
 * nilai 2.000 jt, dibayar 1.260 jt → sisa 740 jt; dari sisa itu 690 jt sudah ber-COP
 * (340 + 350) → belum tertagih 50 jt. Artinya: 50 jt pekerjaan yang belum ditagihkan
 * sama sekali.
 *
 * ── "SUDAH TERTAGIH" ADALAH TAGIHAN YANG BELUM LUNAS, BUKAN SELURUH TAGIHAN ──
 *
 * Yang dijumlahkan adalah SISA tiap tagihan (total − diterima), bukan nilai penuhnya.
 * Tagihan yang sudah dibayar penuh tidak lagi menjadi bagian dari sisa pembayaran — ia
 * sudah terhitung di kolom Riwayat Pembayaran. Menjumlahkan nilai penuh seluruh tagihan
 * menghitung uang yang sama dua kali, dan Belum Tertagih berubah negatif tanpa sebab yang
 * bisa dijelaskan.
 *
 * ── TANGGALNYA TANGGAL TAGIHAN, BUKAN TANGGAL PEMBAYARAN ──
 *
 * MUK tidak mengirim tanggal pembayaran di mana pun: yang ada hanya `diterima` per tagihan
 * dan `kum_diterima` kumulatif pada kontrak. Baris pembayaran karena itu bertanggal
 * TAGIHANNYA, dan layar menyebutnya apa adanya. Menyajikannya sebagai tanggal pembayaran
 * akan dipakai orang untuk merekonsiliasi bank, dan tidak akan pernah cocok.
 *
 * ── PEMBAYARAN DARI BO BELUM BISA IKUT ──
 *
 * $pembayaranBo berisi kuitansi AP yang SUDAH DISETUJUI dan tertaut ke tagihan MUK lewat
 * bs_muk_tagihan (invoice ber-source_type 'muk_tagihan'). Jembatan itu baru ada sejak layar
 * Tagihan Progress Project; sebelumnya invoice AP hanya menunjuk register SPK internal dan
 * tidak pernah cocok dengan kontrak MUK.
 *
 * UNTUK TAGIHAN YANG DIBAYAR LEWAT BO, `diterima` dari MUK DILEWATI. Keduanya menggambarkan
 * uang yang sama begitu MUK ikut mencatat pembayaran kita, dan menjumlahkannya membuat Sisa
 * Pembayaran menyusut dua kali lipat tanpa satu pun galat.
 */
class RincianCtc
{
    /**
     * @param  array<int, array<string, mixed>>  $tagihan  dari MukApi::tagihan()
     * @param  array<int, array{tanggal: ?string, nominal: float, sumber: string, keterangan: ?string, no_tagihan?: ?string}>  $pembayaranBo
     * @return array{
     *     pembayaran: array<int, array<string, mixed>>, total_pembayaran: float,
     *     sisa_pembayaran: float, tertagih: array<int, array<string, mixed>>,
     *     sudah_tertagih: float, belum_tertagih: float, selisih_diterima: ?float
     * }
     */
    public function untuk(float $nilaiTagihan, float $kumDiterima, array $tagihan, array $pembayaranBo = []): array
    {
        $pembayaran = [];

        // Tagihan yang pembayarannya SUDAH tercatat di BO. Untuk tagihan itu, `diterima`
        // kiriman MUK DILEWATI — kalau keduanya dijumlahkan, uang yang sama terhitung dua
        // kali begitu MUK ikut mencatat pembayaran yang kita kirim, dan Sisa Pembayaran
        // menyusut dua kali lipat tanpa satu pun galat.
        //
        // Aturannya berbasis data, bukan tanggal pisah-batas: tagihan yang diposting lewat
        // BO dihitung dari BO, sisanya dari MUK. Tidak ada yang perlu disetel, dan tidak ada
        // tagihan yang jatuh di antara keduanya.
        $dariBo = [];
        foreach ($pembayaranBo as $p) {
            $no = trim((string) ($p['no_tagihan'] ?? ''));
            if ($no !== '') {
                $dariBo[$no] = true;
            }
        }

        foreach ($tagihan as $t) {
            $diterima = (float) ($t['diterima'] ?? 0);
            if ($diterima <= 0 || isset($dariBo[trim((string) ($t['no_tagihan'] ?? ''))])) {
                continue;
            }

            $pembayaran[] = [
                'tanggal' => $t['tanggal'] ?? null,
                'nominal' => $diterima,
                'sumber' => 'muk',
                'keterangan' => 'Tagihan '.($t['no_tagihan'] ?? '—'),
            ];
        }

        foreach ($pembayaranBo as $p) {
            $pembayaran[] = [
                'tanggal' => $p['tanggal'] ?? null,
                'nominal' => (float) ($p['nominal'] ?? 0),
                'sumber' => $p['sumber'] ?? 'bo',
                'keterangan' => $p['keterangan'] ?? null,
            ];
        }

        usort($pembayaran, fn ($a, $b) => strcmp((string) ($a['tanggal'] ?? ''), (string) ($b['tanggal'] ?? '')));

        $totalBayar = array_sum(array_column($pembayaran, 'nominal'));
        $sisa = $nilaiTagihan - $totalBayar;

        // Tagihan yang MASIH BERSISA. Yang sudah lunas tidak ikut — ia sudah terhitung di
        // Riwayat Pembayaran, dan menghitungnya lagi di sini menghitung uang yang sama dua kali.
        $tertagih = [];
        foreach ($tagihan as $t) {
            $sisaTagihan = (float) ($t['total'] ?? 0) - (float) ($t['diterima'] ?? 0);
            if ($sisaTagihan <= 0.005) {
                continue;
            }

            $tertagih[] = [
                'tanggal' => $t['tanggal'] ?? null,
                'no_tagihan' => $t['no_tagihan'] ?? null,
                'nominal' => $sisaTagihan,
            ];
        }

        $sudah = array_sum(array_column($tertagih, 'nominal'));

        // Angka MUK sendiri kadang tidak konsisten: jumlah `diterima` per tagihan tidak selalu
        // sama dengan `kum_diterima` di kepala kontrak. Selisihnya DIPULANGKAN supaya layar
        // bisa mengatakannya — bukan diam-diam dipilih salah satunya, karena yang memilih
        // diam-diam itu yang membuat orang menagih nominal yang keliru.
        $selisih = abs($kumDiterima - $totalBayar) >= 0.005 ? $kumDiterima - $totalBayar : null;

        return [
            'pembayaran' => $pembayaran,
            'total_pembayaran' => $totalBayar,
            'sisa_pembayaran' => $sisa,
            'tertagih' => $tertagih,
            'sudah_tertagih' => $sudah,
            // TIDAK dijepit ke nol di sini. Belum Tertagih negatif berarti MUK menerbitkan
            // COP melebihi sisa kontraknya — keadaan yang harus TERLIHAT dan ditanyakan,
            // bukan dirapikan menjadi nol sehingga terbaca "sudah beres".
            'belum_tertagih' => $sisa - $sudah,
            'selisih_diterima' => $selisih,
        ];
    }
}
