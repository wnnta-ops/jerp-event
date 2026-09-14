<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

/**
 * Analisa pergerakan angka laporan: apa yang paling menggerakkannya, seberapa besar
 * peranannya terhadap keseluruhan, dan apa yang menahannya dari arah sebaliknya.
 *
 * DIURUT MENURUT RUPIAH, BUKAN PERSENTASE. Akun kecil yang naik dari Rp 100 ribu ke Rp 300
 * ribu adalah kenaikan 200% tetapi tidak menggerakkan angka totalnya sama sekali; yang perlu
 * dilihat orang adalah yang benar-benar memindahkan angkanya. Persentase tetap disebut
 * sebagai pelengkap, bukan sebagai dasar pengurutan.
 *
 * Kelas ini SENGAJA tidak menyentuh basis data. Ia hanya menerima pasangan angka
 * "sekarang vs pembanding" beserta namanya, lalu memulangkan peringkat dan kalimatnya. Dengan
 * begitu ketiga laporan (Neraca, Laba Rugi, Laba Rugi vs Budget) memakai cara baca yang sama
 * persis, dan aturannya bisa diuji tanpa menyiapkan jurnal apa pun.
 */
final class AnalisaKenaikan
{
    /** Banyaknya penggerak yang disebut namanya di tiap arah. */
    public const DISEBUT = 5;

    /** Pergerakan di bawah nilai ini dianggap tidak berarti dan tidak ikut dianalisa. */
    public const AMBANG = 0.005;

    /**
     * @param  array<int,array{kode?:string,nama:string,grup?:string,kini:float,lalu:float,dampak?:int|null}>  $baris
     *                                                                                                        `dampak` = arah sumbangan baris itu terhadap LABA: +1 (pendapatan) atau −1 (beban).
     *                                                                                                        Kosong untuk akun neraca, yang memang tidak menyentuh laba.
     * @return array<string,mixed>
     */
    public static function susun(array $baris, string $labelKini, string $labelLalu): array
    {
        $gerak = [];
        /*
         * Dampak ke laba dijumlahkan TERPISAH dari jumlah kenaikan, dan ini bukan kehalusan
         * yang bisa diabaikan: pendapatan naik 150 juta dan beban naik 20 juta dua-duanya
         * "naik" pada akunnya masing-masing, tetapi bagi laba yang satu menambah dan yang satu
         * menggerus. Menjumlahkan keduanya sebagai satu angka "perubahan bersih" menghasilkan
         * 170 juta — angka yang terdengar masuk akal, terbaca seperti kenaikan laba, dan
         * meleset 40 juta dari kenaikan laba yang sebenarnya.
         */
        $dampak    = 0.0;
        $adaDampak = false;

        foreach ($baris as $b) {
            $kini  = round((float) $b['kini'], 2);
            $lalu  = round((float) $b['lalu'], 2);
            $delta = round($kini - $lalu, 2);
            if (abs($delta) < self::AMBANG) {
                continue;
            }
            if (($b['dampak'] ?? null) !== null) {
                $adaDampak = true;
                $dampak += (int) $b['dampak'] * $delta;
            }
            $gerak[] = [
                'kode'  => (string) ($b['kode'] ?? ''),
                'nama'  => (string) $b['nama'],
                'grup'  => (string) ($b['grup'] ?? ''),
                'kini'  => $kini,
                'lalu'  => $lalu,
                'delta' => $delta,
                // Persentase TIDAK ADA (null) bila pembandingnya nol — bukan 0%, bukan 100%.
                // Naik dari nol bukan "naik tak terhingga persen", melainkan hal baru yang
                // sebelumnya memang belum ada, dan itu yang seharusnya dibaca orang.
                'pct'   => abs($lalu) > self::AMBANG ? round($delta / abs($lalu) * 100, 1) : null,
                'baru'  => abs($lalu) < self::AMBANG,
            ];
        }

        $naik  = array_values(array_filter($gerak, fn ($g) => $g['delta'] > 0));
        $turun = array_values(array_filter($gerak, fn ($g) => $g['delta'] < 0));
        usort($naik, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        usort($turun, fn ($a, $b) => $a['delta'] <=> $b['delta']);

        $jumlahNaik  = round(array_sum(array_column($naik, 'delta')), 2);
        $jumlahTurun = round(array_sum(array_column($turun, 'delta')), 2);

        return [
            'label_kini'   => $labelKini,
            'label_lalu'   => $labelLalu,
            'naik'         => array_slice($naik, 0, self::DISEBUT),
            'turun'        => array_slice($turun, 0, self::DISEBUT),
            'jumlah_naik'  => $jumlahNaik,
            'jumlah_turun' => $jumlahTurun,
            // Selisih seluruh kenaikan dan seluruh penurunan — BUKAN perubahan laba.
            // Untuk laba, pakai 'dampak_laba'.
            'netto'        => round($jumlahNaik + $jumlahTurun, 2),
            'dampak_laba'  => $adaDampak ? round($dampak, 2) : null,
            'akun_naik'    => count($naik),
            'akun_turun'   => count($turun),
            // Seberapa terpusat kenaikannya: bagian yang disumbang lima akun teratas.
            'pusat_pct'    => $jumlahNaik > self::AMBANG
                ? round(array_sum(array_column(array_slice($naik, 0, self::DISEBUT), 'delta')) / $jumlahNaik * 100, 1)
                : null,
        ];
    }
}
