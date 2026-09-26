<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use Illuminate\Support\Collection;

/**
 * COGS Baru — seluruh aritmetika lampiran "MODUL HPP.xlsx", di satu tempat.
 *
 * Berdiri sebagai kelas sendiri, bukan method privat di pengendali, karena semua isinya adalah
 * hitungan yang salahnya TIDAK memunculkan galat apa pun — hanya angka HPP per unit yang keliru.
 * Keempat layar (Perhitungan COGS, Set Up Kategori, Actual Costing, Rincian COGS) membaca dari
 * SATU pemanggilan kelas ini, supaya tidak pernah ada dua jawaban untuk "HPP unit ini berapa".
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *  RANTAI MELINGKAR (CIRCULAR) — DISELESAIKAN TERTUTUP, BUKAN DIITERASI
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * Di lampiran, realisasi tiap unit berbunyi:
 *
 *     O(u) = JIKA unit "SUDAH BOOKED" + "STANDARD COSTING" -> 0
 *            selain itu -> (A − D3 − D4) / Σ LT(belum booked) × LT(u)
 *
 * dengan  D3 = Σ BUDGET tanah atas unit sudah-booked yang memilih STANDARD COSTING
 *         D4 = Σ REALISASI tanah atas unit sudah-booked yang memilih REALISASI COSTING
 *
 * D4 dijumlahkan dari kolom O, sedangkan kolom O memakai D4. Excel hanya bisa menyelesaikannya
 * dengan "iterative calculation" — yang harus dinyalakan manual, bisa berhenti sebelum konvergen,
 * dan diam-diam memulangkan angka yang belum stabil. Di sini rantai itu TIDAK diiterasi sama
 * sekali, melainkan dipecahkan aljabarnya:
 *
 *     misal  r      = tarif per satuan luas
 *            W_bb   = Σ luas unit BELUM booked
 *            W_br   = Σ luas unit SUDAH booked yang MASIH menanggung realisasi
 *            D4     = r × W_br
 *
 *     r = (A − D3 − r·W_br) / W_bb
 *     r·W_bb = A − D3 − r·W_br
 *     r·(W_bb + W_br) = A − D3
 *     ───────────────────────────────
 *     r = (A − D3) / (W_bb + W_br)
 *     ───────────────────────────────
 *
 * Satu pembagian, sekali jalan, tanpa iterasi, tanpa setelan Excel, tanpa kemungkinan tidak
 * konvergen. Akibatnya sebuah sifat yang bisa diuji:
 *
 *     Σ realisasi seluruh unit yang menanggung  +  Σ budget unit terkunci  =  A, PERSIS.
 *
 * Artinya seluruh biaya aktual habis dibebankan — tidak ada rupiah yang hilang maupun dobel.
 *
 * ── "Menanggung" didefinisikan lebih longgar daripada lampiran, dan itu disengaja ──
 * Di lampiran, penyebutnya hanya Σ luas unit BELUM booked, sementara syarat yang membebaskan
 * unit adalah "SUDAH BOOKED" DAN "STANDARD COSTING". Unit sudah-booked yang pilihan costing-nya
 * BELUM ditetapkan karena itu tetap dibebani TETAPI tidak ikut dihitung di D4 — dan total
 * realisasinya berhenti sama dengan biaya aktual, tanpa satu pun galat. Di sini penyebutnya
 * adalah Σ luas SELURUH unit yang tidak terkunci, sehingga rekonsiliasinya selalu tertutup.
 * Bila setiap unit booked sudah memilih costing-nya, rumus ini sama persis dengan lampiran.
 *
 * ── Pembagi nol tidak pernah dibagi ──
 * Bila seluruh unit terkunci (W = 0), tarifnya TIDAK dihitung dan realisasinya nol dengan alasan
 * tertulis — bukan dibagi rata, bukan pula dibiarkan menjadi INF/NAN yang lolos ke layar sebagai
 * angka. Begitu pula "% HPP/Revenue" saat revenue-nya nol: dipulangkan null, bukan #DIV/0!.
 */
class Cogs2Kalkulator
{
    /** Ambang "bukan nol" untuk luas (m2) dan pembagi lain. */
    private const EPS = 0.009;

    public const KOMPONEN = [
        'tanah'   => ['label' => 'Tanah', 'bobot' => 'lt'],
        'housing' => ['label' => 'Housing', 'bobot' => 'lb'],
        'infra'   => ['label' => 'Infrastruktur', 'bobot' => 'lt'],
        'license' => ['label' => 'License Permit', 'bobot' => 'lt'],
    ];

    /**
     * @param  Collection<int,\App\Domain\BusinessSupport\Models\BsCogs2HousingTipe>  $tipe
     * @param  Collection<int,\App\Domain\BusinessSupport\Models\BsCogs2Kategori>  $kategori
     * @param  Collection<int,\App\Domain\BusinessSupport\Models\BsCogs2Actual>  $actual
     * @param  Collection<int,\App\Domain\BusinessSupport\Models\BsCogs2Unit>  $unit
     * @return array<string,mixed>
     */
    public function hitung(BsCogs2Periode $p, Collection $tipe, Collection $kategori, Collection $actual, Collection $unit): array
    {
        $luasLahan = round((float) $p->luas_lahan, 2);
        $saleable = round((float) $p->saleable, 2);
        $rth = round((float) $p->rth, 2);
        $luasBangunan = round((float) $p->luas_bangunan, 2);
        $jalan = round($luasLahan - $saleable - $rth, 2);

        // ── Daftar unit dinormalkan sekali di depan ───────────────────────────────────
        $units = $unit->map(fn ($u) => [
            'id'        => $u->id,
            'urut'      => (int) $u->urut,
            'no_spr'    => $u->no_spr,
            'customer'  => $u->nama_customer,
            'unit'      => (string) $u->unit_rumah,
            'lokasi'    => $u->lokasi,
            'tahap'     => $u->tahap,
            'tipe'      => $u->tipe,
            'lt'        => round((float) $u->lt, 2),
            'lb'        => round((float) $u->lb, 2),
            'status'    => (string) $u->status,
            'costing'   => (string) $u->costing,
            'revenue'   => $u->booked_revenue === null ? null : round((float) $u->booked_revenue, 2),
            'terkunci'  => $u->status === 'sudah' && $u->costing === 'standard',
        ])->values()->all();

        $sigma = fn (string $kunci, ?callable $saring = null) => round(array_sum(array_map(
            fn (array $u) => $saring === null || $saring($u) ? (float) $u[$kunci] : 0.0,
            $units,
        )), 2);

        $ltBooked = $sigma('lt', fn ($u) => $u['status'] === 'sudah');
        // Mengikuti lampiran: Saleable Belum Booked = Saleable yang DIKETIK dikurangi luas tanah
        // unit yang sudah booked — bukan Σ luas unit belum booked. Keduanya berbeda begitu Σ luas
        // seluruh unit tidak sama dengan angka Saleable yang diketik, dan selisihnya dilaporkan
        // di blok rekonsiliasi alih-alih ditambal diam-diam.
        $saleableBelum = round($saleable - $ltBooked, 2);

        // ── 1. Budget per komponen (Set Up Kategori COGS) ─────────────────────────────
        $budgetKomponen = [];
        foreach (array_keys(self::KOMPONEN) as $k) {
            $budgetKomponen[$k] = round((float) $kategori->where('komponen', $k)->sum(fn ($r) => (float) $r->nominal), 2);
        }
        $budgetTotal = round(array_sum($budgetKomponen), 2);

        // Tarif budget per m2 saleable. Housing TIDAK punya tarif tunggal — ia bertarif per TIPE,
        // karena luas bangunan tiap tipe berbeda; di lampiran pun kolomnya diisi "-".
        $tarifBudget = [];
        foreach (['tanah', 'infra', 'license'] as $k) {
            $tarifBudget[$k] = $saleable > self::EPS ? $budgetKomponen[$k] / $saleable : null;
        }
        $tarifBudget['housing'] = null;

        // ── 2. Housing per tipe ───────────────────────────────────────────────────────
        $barisHousing = $kategori->where('komponen', 'housing')->values();
        $unitPerTipe = [];
        $bookedPerTipe = [];
        foreach ($units as $u) {
            $t = (string) ($u['tipe'] ?? '');
            $unitPerTipe[$t] = ($unitPerTipe[$t] ?? 0) + 1;
            if ($u['status'] === 'sudah') {
                $bookedPerTipe[$t] = ($bookedPerTipe[$t] ?? 0) + 1;
            }
        }

        // Realisasi Housing per baris SPK: dicocokkan lewat (nomor SPK + kategori), sama seperti
        // SUMIFS di lampiran.
        $actualHousing = $actual->where('komponen', 'housing');
        $realisasiBaris = [];
        foreach ($barisHousing as $i => $r) {
            $realisasiBaris[$i] = round((float) $actualHousing
                ->filter(fn ($a) => (string) $a->no_spk === (string) $r->no_spk && (string) $a->kategori === (string) $r->kategori)
                ->sum(fn ($a) => (float) $a->penambahan - (float) $a->pengurangan), 2);
        }

        $tipeHasil = [];
        foreach ($tipe as $t) {
            $nama = (string) $t->nama;
            $lb = round((float) $t->luas_bangunan, 2);
            $jml = $unitPerTipe[$nama] ?? 0;
            $booked = $bookedPerTipe[$nama] ?? 0;

            $budgetTipe = 0.0;
            $realisasiTipe = 0.0;
            foreach ($barisHousing as $i => $r) {
                if ((string) $r->kategori !== $nama) {
                    continue;
                }
                $budgetTipe = round($budgetTipe + (float) $r->nominal, 2);
                $realisasiTipe = round($realisasiTipe + $realisasiBaris[$i], 2);
            }

            $budgetPerUnit = $jml > 0 ? $budgetTipe / $jml : null;
            $budgetPerMeter = $budgetPerUnit !== null && $lb > self::EPS ? $budgetPerUnit / $lb : null;

            // Budget yang SUDAH terealisasikan lewat unit booked: total HPP budget unit tipe ini
            // yang sudah booked (kolom I di lampiran).
            $budgetTerealisasi = 0.0;

            // Sisa unit tipe ini yang belum booked — penyebut realisasi per unit. Di lampiran
            // baris pertama memakai jumlah booked SELURUH tipe sedangkan baris lain tidak
            // menguranginya sama sekali; keduanya kebetulan sama hari ini karena belum ada unit
            // booked. Yang dipakai di sini adalah yang satu-satunya masuk akal: sisa unit TIPE INI.
            $sisaUnit = $jml - $booked;

            $tipeHasil[] = [
                'nama'              => $nama,
                'luas_bangunan'     => $lb,
                'jumlah_unit'       => $jml,
                'jumlah_booked'     => $booked,
                'budget_total'      => $budgetTipe,
                'budget_per_unit'   => $budgetPerUnit,
                'budget_per_meter'  => $budgetPerMeter,
                'realisasi_total'   => $realisasiTipe,
                'budget_terealisasi' => $budgetTerealisasi,   // diisi setelah budget per unit diketahui
                'sisa_unit'         => $sisaUnit,
                'realisasi_per_unit' => null,
                'realisasi_per_meter' => null,
                'alasan'            => $jml === 0
                    ? 'Belum ada unit bertipe ini di Rincian COGS'
                    : ($lb <= self::EPS ? 'Luas bangunan tipe ini belum diisi' : null),
            ];
        }
        $meterPerTipe = collect($tipeHasil)->keyBy('nama')->map(fn ($t) => $t['budget_per_meter'])->all();

        // ── 3. Budget per unit (kolom J..N di lampiran) ───────────────────────────────
        foreach ($units as $i => $u) {
            $lt = (float) $u['lt'];
            $lb = (float) $u['lb'];
            $mt = $meterPerTipe[(string) ($u['tipe'] ?? '')] ?? null;

            $b = [
                'tanah'   => $tarifBudget['tanah'] === null ? 0.0 : round($lt * $tarifBudget['tanah'], 2),
                'housing' => $mt === null ? 0.0 : round($lb * $mt, 2),
                'infra'   => $tarifBudget['infra'] === null ? 0.0 : round($lt * $tarifBudget['infra'], 2),
                'license' => $tarifBudget['license'] === null ? 0.0 : round($lt * $tarifBudget['license'], 2),
            ];
            $units[$i]['budget'] = $b;
            $units[$i]['budget_total'] = round(array_sum($b), 2);
        }

        // Budget terealisasikan per tipe baru bisa dihitung setelah budget per unit ada.
        foreach ($tipeHasil as $i => $t) {
            $tipeHasil[$i]['budget_terealisasi'] = round(array_sum(array_map(
                fn (array $u) => $u['status'] === 'sudah' && (string) ($u['tipe'] ?? '') === $t['nama'] ? (float) $u['budget_total'] : 0.0,
                $units,
            )), 2);

            $sisa = $t['sisa_unit'];
            $rt = round($tipeHasil[$i]['realisasi_total'] - $tipeHasil[$i]['budget_terealisasi'], 2);
            $tipeHasil[$i]['realisasi_per_unit'] = $sisa > 0 ? $rt / $sisa : null;
            $tipeHasil[$i]['realisasi_per_meter'] = $tipeHasil[$i]['realisasi_per_unit'] !== null && $t['luas_bangunan'] > self::EPS
                ? $tipeHasil[$i]['realisasi_per_unit'] / $t['luas_bangunan']
                : null;
            if ($sisa <= 0 && $tipeHasil[$i]['alasan'] === null) {
                $tipeHasil[$i]['alasan'] = 'Seluruh unit tipe ini sudah booked — tidak ada sisa unit untuk menanggung realisasinya';
            }
        }

        // ── 4. Realisasi aktual per komponen ──────────────────────────────────────────
        $actualKomponen = [];
        foreach (array_keys(self::KOMPONEN) as $k) {
            $actualKomponen[$k] = round((float) $actual->where('komponen', $k)
                ->sum(fn ($a) => (float) $a->penambahan - (float) $a->pengurangan), 2);
        }

        // ── 5. Rantai melingkar, diselesaikan tertutup ────────────────────────────────
        $tarifRealisasi = [];
        $alasanRealisasi = [];
        $bobotMenanggung = [];
        $budgetTerkunci = [];

        foreach (self::KOMPONEN as $k => $def) {
            $w = $def['bobot'];

            // Budget unit yang TERKUNCI (sudah booked + standard costing) — sudah tidak menanggung.
            $budgetTerkunci[$k] = round(array_sum(array_map(
                fn (array $u) => $u['terkunci'] ? (float) $u['budget'][$k] : 0.0,
                $units,
            )), 2);

            // Σ bobot seluruh unit yang MASIH menanggung.
            $bobotMenanggung[$k] = round(array_sum(array_map(
                fn (array $u) => $u['terkunci'] ? 0.0 : (float) $u[$w],
                $units,
            )), 2);

            if ($bobotMenanggung[$k] <= self::EPS) {
                $tarifRealisasi[$k] = null;
                $alasanRealisasi[$k] = $units === []
                    ? 'Belum ada unit pada periode ini'
                    : 'Seluruh unit sudah booked dengan Standard Costing — tidak ada unit yang menanggung realisasi berjalan';

                continue;
            }

            $tarifRealisasi[$k] = ($actualKomponen[$k] - $budgetTerkunci[$k]) / $bobotMenanggung[$k];
            $alasanRealisasi[$k] = null;
        }

        // Pembagian ke unit. Sisa pembulatan diserap unit TERAKHIR yang menanggung supaya
        // Σ realisasi + Σ budget terkunci = biaya aktual, PERSIS — bukan meleset beberapa rupiah
        // yang terlalu kecil untuk terlihat dan cukup untuk merusak rekonsiliasinya.
        foreach (array_keys(self::KOMPONEN) as $k) {
            $w = self::KOMPONEN[$k]['bobot'];
            $target = round($actualKomponen[$k] - $budgetTerkunci[$k], 2);
            $terakhir = null;
            foreach ($units as $i => $u) {
                if (! $u['terkunci'] && (float) $u[$w] > self::EPS) {
                    $terakhir = $i;
                }
            }

            $terpakai = 0.0;
            foreach ($units as $i => $u) {
                if ($u['terkunci'] || $tarifRealisasi[$k] === null || (float) $u[$w] <= self::EPS) {
                    $units[$i]['realisasi'][$k] = 0.0;

                    continue;
                }
                $nilai = $i === $terakhir
                    ? round($target - $terpakai, 2)
                    : round((float) $u[$w] * $tarifRealisasi[$k], 2);
                $units[$i]['realisasi'][$k] = $nilai;
                $terpakai = round($terpakai + $nilai, 2);
            }
        }

        foreach ($units as $i => $u) {
            $units[$i]['realisasi_total'] = round(array_sum($u['realisasi'] ?? []), 2);
            // "% HPP/Revenue" — null saat revenue nol, BUKAN #DIV/0! seperti di lampiran.
            $rev = $u['revenue'];
            $units[$i]['persen_hpp'] = $rev !== null && abs($rev) > self::EPS
                ? $units[$i]['budget_total'] / $rev
                : null;
            $units[$i]['no'] = $i + 1;
        }

        // ── 6. Ringkasan Rincian COGS (baris 3/4/5 di lampiran) ───────────────────────
        $jumlahKomp = function (callable $saring, string $sumber) use ($units) {
            $out = [];
            foreach (array_keys(self::KOMPONEN) as $k) {
                $out[$k] = round(array_sum(array_map(
                    fn (array $u) => $saring($u) ? (float) ($u[$sumber][$k] ?? 0) : 0.0,
                    $units,
                )), 2);
            }
            $out['total'] = round(array_sum($out), 2);

            return $out;
        };

        $ringkas = [
            // 1. Sudah booked + Standard Costing → memakai angka BUDGET (HPP-nya terkunci).
            'booked_standard' => $jumlahKomp(fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'standard', 'budget')
                + ['jumlah' => count(array_filter($units, fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'standard'))],
            // 2. Sudah booked + Realisasi Costing → memakai angka REALISASI.
            'booked_realisasi' => $jumlahKomp(fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'realisasi', 'realisasi')
                + ['jumlah' => count(array_filter($units, fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'realisasi'))],
            // 3. Belum booked → keduanya masih mungkin, jadi angka BUDGET yang ditampilkan.
            'belum_booked' => $jumlahKomp(fn ($u) => $u['status'] === 'belum', 'budget')
                + ['jumlah' => count(array_filter($units, fn ($u) => $u['status'] === 'belum'))],
            // Baris tambahan yang tidak ada di lampiran tetapi diperlukan untuk menutup
            // rekonsiliasi: unit sudah booked yang pilihan costing-nya BELUM ditetapkan.
            'booked_belum_pilih' => $jumlahKomp(fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'belum', 'realisasi')
                + ['jumlah' => count(array_filter($units, fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'belum'))],
        ];

        // ── 7. Rekonsiliasi: biaya aktual harus habis dibebankan ──────────────────────
        $rekon = [];
        foreach (array_keys(self::KOMPONEN) as $k) {
            $dibebankan = round(array_sum(array_map(fn (array $u) => (float) ($u['realisasi'][$k] ?? 0), $units)), 2);
            $rekon[$k] = [
                'aktual'         => $actualKomponen[$k],
                'terkunci'       => $budgetTerkunci[$k],
                'dibebankan'     => $dibebankan,
                'selisih'        => round($actualKomponen[$k] - $budgetTerkunci[$k] - $dibebankan, 2),
                'bobot'          => $bobotMenanggung[$k],
                'tarif'          => $tarifRealisasi[$k],
                'alasan'         => $alasanRealisasi[$k],
            ];
        }

        // ── 8. Perhitungan COGS (lembar ringkasan) ────────────────────────────────────
        $budgetBelumBooked = $jumlahKomp(fn ($u) => $u['status'] === 'belum', 'budget');
        $perhitungan = [];
        foreach (self::KOMPONEN as $k => $def) {
            $perhitungan[$k] = [
                'label'           => $def['label'],
                'budget'          => $budgetKomponen[$k],
                'budget_per_m2'   => $tarifBudget[$k],
                'realisasi'       => $budgetBelumBooked[$k],
                'realisasi_per_m2' => $k === 'housing' || $saleableBelum <= self::EPS
                    ? null
                    : $budgetBelumBooked[$k] / $saleableBelum,
            ];
        }

        $sigmaLt = $sigma('lt');
        $sigmaLb = $sigma('lb');

        // ── 9. Peringatan: keadaan yang angkanya TETAP keluar tetapi menyesatkan ──────
        // Semuanya adalah kelas kegagalan yang di lampiran tidak memunculkan galat apa pun.
        $peringatan = [];

        // (a) Tarif negatif. BUKAN hipotetis: budget Tanah pada lampiran (16,18 M) sudah melampaui
        //     biaya aktualnya (12,15 M), sehingga begitu cukup banyak unit terkunci di Standard
        //     Costing, sisa biaya yang harus dibebankan menjadi negatif dan unit yang tersisa
        //     menerima HPP negatif — angka yang lolos seluruh penjumlahan tanpa terlihat salah.
        foreach (self::KOMPONEN as $k => $def) {
            if ($tarifRealisasi[$k] !== null && $tarifRealisasi[$k] < 0) {
                $peringatan[] = [
                    'jenis' => 'tarif_negatif',
                    'pesan' => $def['label'].': budget unit yang sudah terkunci ('.number_format($budgetTerkunci[$k], 2, ',', '.')
                        .') melampaui biaya aktualnya ('.number_format($actualKomponen[$k], 2, ',', '.')
                        .'), sehingga unit yang tersisa menerima HPP realisasi NEGATIF.',
                ];
            }
        }

        // (b) Tarif tidak bisa dihitung — sisa biayanya menggantung, tidak terbebankan ke siapa pun.
        foreach (self::KOMPONEN as $k => $def) {
            if ($alasanRealisasi[$k] !== null && abs(round($actualKomponen[$k] - $budgetTerkunci[$k], 2)) > 0.005) {
                $peringatan[] = [
                    'jenis' => 'tak_terbebankan',
                    'pesan' => $def['label'].': '.$alasanRealisasi[$k].'. Sisa '
                        .number_format($actualKomponen[$k] - $budgetTerkunci[$k], 2, ',', '.').' tidak terbebankan ke unit mana pun.',
                ];
            }
        }

        // (c) Tipe unit tanpa padanan di master Housing → budget housing-nya NOL tanpa jejak.
        $namaTipe = collect($tipeHasil)->pluck('nama')->all();
        $tanpaTipe = [];
        foreach ($units as $u) {
            $t = trim((string) ($u['tipe'] ?? ''));
            if ($t === '' || ! in_array($t, $namaTipe, true)) {
                $tanpaTipe[$t === '' ? '(kosong)' : $t] = ($tanpaTipe[$t === '' ? '(kosong)' : $t] ?? 0) + 1;
            }
        }
        foreach ($tanpaTipe as $t => $n) {
            $peringatan[] = [
                'jenis' => 'tipe_yatim',
                'pesan' => $n.' unit bertipe "'.$t.'" tidak punya padanan di Set Up Kategori COGS-Housing, '
                    .'jadi budget Housing-nya nol — bukan karena memang nol.',
            ];
        }

        // (d) Tipe kembar di master → tarifnya berlipat, dan jumlah unitnya terbagi dua kali.
        $kembar = collect($tipeHasil)->pluck('nama')->countBy()->filter(fn ($n) => $n > 1);
        foreach ($kembar as $nama => $n) {
            $peringatan[] = [
                'jenis' => 'tipe_kembar',
                'pesan' => 'Tipe "'.$nama.'" tercantum '.$n.' kali di Set Up Kategori COGS-Housing — '
                    .'hanya baris terakhir yang dipakai menghitung budget per meter.',
            ];
        }

        // (e) Unit tanpa luas → tidak menanggung apa pun, dan tampil seolah HPP-nya nol.
        $nolLt = count(array_filter($units, fn ($u) => (float) $u['lt'] <= self::EPS));
        $nolLb = count(array_filter($units, fn ($u) => (float) $u['lb'] <= self::EPS));
        if ($nolLt > 0) {
            $peringatan[] = ['jenis' => 'luas_nol', 'pesan' => $nolLt.' unit tanpa luas tanah — Tanah, Infrastruktur & License Permit-nya nol.'];
        }
        if ($nolLb > 0) {
            $peringatan[] = ['jenis' => 'luas_nol', 'pesan' => $nolLb.' unit tanpa luas bangunan — Housing-nya nol.'];
        }

        // (f) Unit sudah booked yang pilihan costing-nya belum ditetapkan. Di lampiran, unit
        //     seperti ini TETAP dibebani tetapi tidak ikut dijumlahkan di baris ringkasannya,
        //     sehingga totalnya melebihi biaya aktual tanpa ada yang memberi tahu. Di sini ia
        //     ikut ke penyebut sehingga rekonsiliasinya tetap tertutup — tetapi tetap disebut,
        //     karena pilihannya memang masih harus ditetapkan.
        $belumPilih = count(array_filter($units, fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'belum'));
        if ($belumPilih > 0) {
            $peringatan[] = [
                'jenis' => 'costing_belum',
                'pesan' => $belumPilih.' unit sudah booked tetapi pilihan costing-nya belum ditetapkan — '
                    .'sementara ini keduanya dihitung sebagai masih menanggung realisasi berjalan.',
            ];
        }

        return [
            'lahan' => [
                'luas_lahan'      => $luasLahan,
                'saleable'        => $saleable,
                'rth'             => $rth,
                'jalan'           => $jalan,
                'luas_bangunan'   => $luasBangunan,
                'jumlah_unit'     => $p->jumlah_unit,
                'persen_saleable' => $luasLahan > self::EPS ? $saleable / $luasLahan : null,
                'persen_rth'      => $luasLahan > self::EPS ? $rth / $luasLahan : null,
                'persen_jalan'    => $luasLahan > self::EPS ? $jalan / $luasLahan : null,
                'saleable_booked' => $ltBooked,
                'saleable_belum'  => $saleableBelum,
            ],
            'perhitungan' => $perhitungan,
            'total' => [
                'budget'        => $budgetTotal,
                // TIGA angka per m2 dengan penyebut yang disebut namanya masing-masing. Di lampiran
                // "COGS GROSS" dan "COGS Saleable" sama-sama dibagi Saleable Belum Booked sehingga
                // keduanya memulangkan angka yang identik di bawah dua nama berbeda — satu angka
                // per m2 tanpa keterangan penyebutnya adalah sumber salah hitung yang paling mudah
                // terjadi, jadi ketiganya dipisah dan dinamai.
                'per_lahan'     => $luasLahan > self::EPS ? $budgetTotal / $luasLahan : null,
                'per_saleable'  => $saleable > self::EPS ? $budgetTotal / $saleable : null,
                'per_belum'     => $saleableBelum > self::EPS ? $budgetTotal / $saleableBelum : null,
                'aktual'        => round(array_sum($actualKomponen), 2),
                'aktual_per'    => $actualKomponen,
            ],
            'tipe'      => $tipeHasil,
            'housing'   => $barisHousing->values()->map(fn ($r, $i) => [
                'id'         => $r->id,
                'lokasi'     => $r->lokasi,
                'tahap'      => $r->tahap,
                'kategori'   => $r->kategori,
                'no_spk'     => $r->no_spk,
                'nominal'    => round((float) $r->nominal, 2),
                'jumlah_unit' => $unitPerTipe[(string) $r->kategori] ?? 0,
                'per_unit'   => ($unitPerTipe[(string) $r->kategori] ?? 0) > 0
                    ? (float) $r->nominal / $unitPerTipe[(string) $r->kategori]
                    : null,
                'realisasi'  => $realisasiBaris[$i] ?? 0.0,
            ])->all(),
            'unit'       => $units,
            'ringkas'    => $ringkas,
            'rekon'      => $rekon,
            'peringatan' => $peringatan,
            'unitTotal' => [
                'jumlah'        => count($units),
                'lt'            => $sigmaLt,
                'lb'            => $sigmaLb,
                'budget'        => round(array_sum(array_map(fn ($u) => (float) $u['budget_total'], $units)), 2),
                'realisasi'     => round(array_sum(array_map(fn ($u) => (float) $u['realisasi_total'], $units)), 2),
                'revenue'       => round(array_sum(array_map(fn ($u) => (float) ($u['revenue'] ?? 0), $units)), 2),
                'booked'        => count(array_filter($units, fn ($u) => $u['status'] === 'sudah')),
                'terkunci'      => count(array_filter($units, fn ($u) => $u['terkunci'])),
                // Σ luas unit vs angka Saleable / Luas Bangunan yang DIKETIK. Selisih yang bukan
                // nol berarti tarif per m2 memakai pembagi yang berbeda dari luas yang benar-benar
                // dibebani; itu bukan kesalahan hitung, melainkan data yang belum cocok — dan
                // satu-satunya tanda bahwa ada yang perlu dibetulkan.
                'selisih_lt'    => round($saleable - $sigmaLt, 2),
                'selisih_lb'    => round($luasBangunan - $sigmaLb, 2),
            ],
        ];
    }
}
