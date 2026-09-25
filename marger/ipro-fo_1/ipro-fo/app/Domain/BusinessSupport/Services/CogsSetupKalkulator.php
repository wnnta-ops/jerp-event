<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

/**
 * Aritmetika Set Up COGS: dari "Perhitungan Lahan" + pos biaya → budget per unit.
 *
 * Berdiri sebagai kelas sendiri, bukan method privat di pengendali, supaya angkanya bisa diuji
 * langsung tanpa merender layar — seluruh isi kelas ini adalah hitungan yang salahnya TIDAK
 * memunculkan galat apa pun, hanya angka HPP yang keliru.
 *
 * ── Urutan hitungannya (sama persis dengan lampiran) ──
 *   Jalan            = Luas Lahan − Saleable − RTH          (turunan, tidak pernah disimpan)
 *   %Saleable/%RTH/%Jalan = masing-masing ÷ Luas Lahan
 *   tarif komponen   = nilai ÷ pembagi                       (pembaginya tergantung `dasar`)
 *   TOTAL COGS       = Σ nilai seluruh komponen
 *   COGS GROSS       = TOTAL ÷ Luas Lahan
 *   COGS Saleable    = TOTAL ÷ Saleable
 *
 * ── DUA pembagi yang berbeda, dan mengapa keduanya harus disebut ──
 * Di lampiran, tarif tiap komponen dibagi SALEABLE (17.150) sementara COGS GROSS dibagi LUAS
 * LAHAN (31.933). Selisihnya 1,86 kali — cukup besar untuk salah total, cukup masuk akal untuk
 * tidak terlihat salah. Karena itu keduanya dihitung dan dinamai terpisah, tidak pernah ada satu
 * angka "per m2" tanpa keterangan penyebutnya.
 *
 * ── Mengapa pembagi tanah bisa dipilih ──
 * Lampiran memakai Saleable yang DIKETIK. Pembebanannya memakai luas tanah masing-masing unit.
 * Bila Σ luas tanah unit tidak sama dengan Saleable yang diketik, jumlah seluruh bagian TIDAK
 * akan sama dengan TOTAL COGS — dan selisihnya tidak akan memunculkan galat di mana pun. Jadi:
 *   • dasar 'saleable' → setia pada lampiran, dan selisihnya DILAPORKAN apa adanya;
 *   • dasar 'unit'     → pembaginya Σ luas tanah unit, sehingga Σ bagian = nilai, persis.
 * Yang tidak boleh dilakukan adalah memaksakan kecocokan pada jalur 'saleable': itu menyembunyikan
 * bahwa data luasnya memang belum cocok dengan angka yang diketik.
 *
 * ── Sisa pembulatan ──
 * Hanya ketika pembaginya SAMA dengan jumlah bobotnya, sisa pembulatan dibebankan ke unit
 * terakhir yang punya bobot, supaya Σ bagian = nilai komponen, persis. Membulatkan tiap bagian
 * sendiri-sendiri meleset beberapa rupiah — terlalu kecil untuk terlihat, dan angka itu berakhir
 * di HPP per unit.
 *
 * ── Pembagi nol tidak pernah dibagi ──
 * Komponen yang pembaginya nol (tidak ada unit, seluruh luasnya nol, atau Saleable belum diisi)
 * TIDAK dibagi rata dan TIDAK dibuang: nilainya tetap ditampilkan utuh dengan alasan tertulis.
 * Membagi rata menghasilkan budget per unit yang terlihat masuk akal dan salah.
 */
class CogsSetupKalkulator
{
    /** Ambang "bukan nol" untuk luas dalam m2 — sama dengan yang dipakai pembagian di Komponen COGS. */
    private const EPS = 0.009;

    /**
     * @param  array{luas_lahan:float,saleable:float,rth:float,jumlah_unit:?int,dasar_tanah:string,termasuk_lebih_tanah:bool}  $header
     * @param  list<array{nama:string,dasar:string,nilai:float}>  $komponen
     * @param  list<array{kode:string,tipe:?string,blok:?string,no_unit:?string,luas_tanah:float,luas_bangunan:float,luas_lebih_tanah:float}>  $unit
     * @return array<string,mixed>
     */
    public function hitung(array $header, array $komponen, array $unit): array
    {
        $luasLahan = round((float) $header['luas_lahan'], 2);
        $saleable = round((float) $header['saleable'], 2);
        $rth = round((float) $header['rth'], 2);
        $jalan = round($luasLahan - $saleable - $rth, 2);
        $plusLebih = (bool) $header['termasuk_lebih_tanah'];

        // ── Luas per unit ──────────────────────────────────────────────────────────────
        // luas_lebih_tanah ikut atau tidak adalah SETELAN, bukan tebakan: pada sebagian data ia
        // kelebihan di luar luas_tanah, pada sebagian lain sudah termasuk di dalamnya. Keduanya
        // dijumlahkan supaya layar bisa menunjukkan mana yang cocok dengan Saleable yang diketik.
        $baris = [];
        $sigmaLt = 0.0;
        $sigmaLtPolos = 0.0;
        $sigmaLtLebih = 0.0;
        $sigmaLb = 0.0;

        foreach (array_values($unit) as $i => $u) {
            $polos = round((float) ($u['luas_tanah'] ?? 0), 2);
            $lebih = round((float) ($u['luas_lebih_tanah'] ?? 0), 2);
            $lt = round($plusLebih ? $polos + $lebih : $polos, 2);
            $lb = round((float) ($u['luas_bangunan'] ?? 0), 2);

            $baris[] = [
                'no'         => $i + 1,
                'kode'       => (string) $u['kode'],
                'tipe'       => $u['tipe'] ?? null,
                'blok'       => $u['blok'] ?? null,
                'no_unit'    => $u['no_unit'] ?? null,
                'lt'         => $lt,
                'lt_polos'   => $polos,
                'lt_lebih'   => $lebih,
                'lb'         => $lb,
                'bagian'     => [],   // diisi per komponen di bawah
                'total'      => 0.0,
            ];

            $sigmaLtPolos = round($sigmaLtPolos + $polos, 2);
            $sigmaLtLebih = round($sigmaLtLebih + $lebih, 2);
            $sigmaLt = round($sigmaLt + $lt, 2);
            $sigmaLb = round($sigmaLb + $lb, 2);
        }

        // ── Pembagi per dasar ──────────────────────────────────────────────────────────
        $dasarTanah = $header['dasar_tanah'] === 'unit' ? 'unit' : 'saleable';
        $pembagi = [
            'tanah' => [
                'sumber' => $dasarTanah,
                'nilai'  => $dasarTanah === 'unit' ? $sigmaLt : $saleable,
                'bobot'  => $sigmaLt,      // jumlah bobot yang benar-benar dibebani
                'alasan' => null,
            ],
            // Sisi bangunan tidak punya angka ketik di lampiran — satu-satunya sumber yang bisa
            // dipertanggungjawabkan adalah Σ luas bangunan unit yang benar-benar terbaca.
            'bangunan' => [
                'sumber' => 'unit',
                'nilai'  => $sigmaLb,
                'bobot'  => $sigmaLb,
                'alasan' => null,
            ],
        ];
        foreach ($pembagi as $k => $p) {
            if ($p['nilai'] <= self::EPS) {
                $pembagi[$k]['alasan'] = $k === 'tanah'
                    ? ($dasarTanah === 'saleable'
                        ? 'Saleable belum diisi, jadi tarif per m2 tanah tidak bisa dihitung'
                        : 'Tidak ada unit dengan luas tanah pada cakupan ini')
                    : 'Tidak ada unit dengan luas bangunan pada cakupan ini';
            } elseif ($p['bobot'] <= self::EPS) {
                // Pembaginya ada (Saleable diketik) tetapi tidak ada unit yang bisa dibebani.
                $pembagi[$k]['alasan'] = 'Tidak ada unit dengan luas '
                    .($k === 'tanah' ? 'tanah' : 'bangunan').' untuk dibebani';
            }
        }

        // ── Tarif & pembagian per komponen ─────────────────────────────────────────────
        $hasilKomponen = [];
        $totalCogs = 0.0;
        $tarifTanah = 0.0;
        $tarifBangunan = 0.0;

        foreach (array_values($komponen) as $idx => $k) {
            $dasar = ($k['dasar'] ?? 'tanah') === 'bangunan' ? 'bangunan' : 'tanah';
            $nilai = round((float) ($k['nilai'] ?? 0), 2);
            $totalCogs = round($totalCogs + $nilai, 2);

            $p = $pembagi[$dasar];
            $bisa = $p['alasan'] === null && $p['nilai'] > self::EPS;
            $tarif = $bisa ? $nilai / $p['nilai'] : null;

            if ($bisa && $dasar === 'tanah') {
                $tarifTanah += (float) $tarif;
            } elseif ($bisa) {
                $tarifBangunan += (float) $tarif;
            }

            $dibagi = $bisa
                ? $this->bagi($nilai, $baris, $dasar === 'tanah' ? 'lt' : 'lb', (float) $tarif, (float) $p['nilai'], (float) $p['bobot'])
                : array_fill(0, count($baris), 0.0);

            $terbagi = 0.0;
            foreach ($baris as $i => $_) {
                $baris[$i]['bagian'][$idx] = $dibagi[$i];
                $baris[$i]['total'] = round($baris[$i]['total'] + $dibagi[$i], 2);
                $terbagi = round($terbagi + $dibagi[$i], 2);
            }

            $hasilKomponen[] = [
                'nama'     => (string) $k['nama'],
                'dasar'    => $dasar,
                'nilai'    => $nilai,
                'pembagi'  => $bisa ? round((float) $p['nilai'], 2) : null,
                'tarif'    => $tarif,
                'terbagi'  => $terbagi,
                'selisih'  => round($nilai - $terbagi, 2),
                'alasan'   => $bisa ? null : $p['alasan'],
            ];
        }

        // ── Jumlah kolom per komponen (untuk baris kaki tabel unit) ────────────────────
        $kolom = [];
        foreach ($hasilKomponen as $idx => $_) {
            $kolom[$idx] = round(array_sum(array_map(fn (array $b) => (float) $b['bagian'][$idx], $baris)), 2);
        }
        $totalUnit = round(array_sum(array_map(fn (array $b) => (float) $b['total'], $baris)), 2);

        return [
            'lahan' => [
                'luas_lahan'      => $luasLahan,
                'saleable'        => $saleable,
                'rth'             => $rth,
                'jalan'           => $jalan,
                'persen_saleable' => $luasLahan > self::EPS ? $saleable / $luasLahan : null,
                'persen_rth'      => $luasLahan > self::EPS ? $rth / $luasLahan : null,
                'persen_jalan'    => $luasLahan > self::EPS ? $jalan / $luasLahan : null,
                'jumlah_unit'     => $header['jumlah_unit'] ?? null,
            ],
            'komponen' => $hasilKomponen,
            'pembagi'  => $pembagi,
            'total'    => [
                'total_cogs'     => $totalCogs,
                // Dua penyebut yang berbeda, dinamai masing-masing supaya tidak pernah tertukar.
                'cogs_gross'     => $luasLahan > self::EPS ? $totalCogs / $luasLahan : null,
                'cogs_saleable'  => $saleable > self::EPS ? $totalCogs / $saleable : null,
                // Jumlah tarif per dasar. Pada lampiran, Σ tarif tanah SAMA dengan COGS Saleable
                // karena keempat posnya berdasar tanah dan pembaginya Saleable; begitu ada pos
                // berdasar bangunan, kesamaan itu tidak berlaku lagi — jadi disebut terpisah.
                'tarif_tanah'    => $tarifTanah,
                'tarif_bangunan' => $tarifBangunan,
            ],
            'unit'      => $baris,
            'unitKolom' => $kolom,
            'unitTotal' => [
                'lt'       => $sigmaLt,
                'lt_polos' => $sigmaLtPolos,
                'lt_lebih' => $sigmaLtLebih,
                'lb'       => $sigmaLb,
                'total'    => $totalUnit,
                'jumlah'   => count($baris),
            ],
            // Rekonsiliasi: angka yang diketik vs angka yang benar-benar terbaca dari unit.
            // Tanpa baris ini, ketidakcocokan Saleable ↔ Σ luas tanah tidak terlihat di mana pun.
            'rekon' => [
                'saleable'          => $saleable,
                'sigma_lt'          => $sigmaLt,
                'selisih_m2'        => round($saleable - $sigmaLt, 2),
                'selisih_rp'        => round($totalCogs - $totalUnit, 2),
                'jumlah_unit_input' => $header['jumlah_unit'] ?? null,
                'jumlah_unit_baca'  => count($baris),
                'cocok'             => abs(round($saleable - $sigmaLt, 2)) < 0.005,
            ],
        ];
    }

    /**
     * Bagi satu nilai ke unit menurut bobot luas.
     *
     * Sisa pembulatan diserap unit TERAKHIR YANG PUNYA BOBOT — dan hanya bila pembaginya memang
     * sama dengan jumlah bobotnya. Kalau pembaginya angka lain (Saleable yang diketik), jumlah
     * bagian memang tidak sama dengan nilainya, dan selisih itu dilaporkan, bukan ditambal.
     *
     * @param  list<array<string,mixed>>  $baris
     * @return list<float>
     */
    private function bagi(float $nilai, array $baris, string $kunci, float $tarif, float $pembagi, float $bobot): array
    {
        $out = array_fill(0, count($baris), 0.0);
        $serapSisa = abs($pembagi - $bobot) < 0.005 && $bobot > self::EPS;

        $terakhir = null;
        if ($serapSisa) {
            foreach ($baris as $i => $b) {
                if ((float) $b[$kunci] > self::EPS) {
                    $terakhir = $i;
                }
            }
        }

        $terpakai = 0.0;
        foreach ($baris as $i => $b) {
            $luas = (float) $b[$kunci];
            if ($luas <= self::EPS) {
                continue;
            }
            $bagian = $i === $terakhir ? round($nilai - $terpakai, 2) : round($luas * $tarif, 2);
            $out[$i] = $bagian;
            $terpakai = round($terpakai + $bagian, 2);
        }

        return $out;
    }
}
