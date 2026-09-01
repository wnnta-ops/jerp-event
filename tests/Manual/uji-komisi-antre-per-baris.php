<?php

declare(strict_types=1);

/*
 * Uji tombol "Posting BO" pada Realisasi Komisi (sisi FO).
 *
 * Baris pada layar Realisasi Komisi adalah hasil GROUP BY no_pengajuan — satu baris mewakili
 * SELURUH isi pengajuan. Sebelumnya tombolnya ikut memperlakukan batch itu sebagai satu
 * kesatuan: yang dikirim ke BO satu baris berisi TOTAL NETT, dengan nama pembelinya
 * diringkas jadi satu untai. Tiga komisi untuk tiga pembeli berakhir sebagai satu invoice
 * gelondongan — tidak terbaca milik siapa, dan tidak bisa dibayarkan ke rekening
 * masing-masing agen.
 *
 * Yang dijaga di sini: SATU KLIK = SEMUA KOMISI DI DALAMNYA PINDAH, satu per satu, lengkap
 * dengan nilai bruto, potongan PPh 23, nama pembeli, dan blok/kavlingnya. Pengajuan lain
 * tidak boleh ikut terbawa.
 *
 * Angkanya diambil dari "Jurnal AP untuk KOMISI.xlsx" (KOMPENG-202609-00001) supaya
 * pembulatan PPh-nya ikut teruji: 9.346.667 → 186.934 dan 11.044.792 → 220.896 adalah hasil
 * ceil(bruto × 2%), bukan pembulatan biasa.
 *
 * Bentuk jurnal yang dihasilkannya di BO diuji di repo ipro-fo:
 * tests/Manual/uji-komisi-posting-jurnal.php
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — di kedua basis data.
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec jerp-verify php tests/Manual/uji-komisi-antre-per-baris.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\BO\IproBridge\Services\IproApPoster;
use App\Modules\FO\Komisi\Models\Komisi;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$boConn = config('ipro.connection', 'ipro_bo');
$tenantId = (int) (DB::table('fo_kontrak')->value('tenant_id') ?: 1);
app()->instance('tenant.id', $tenantId);

DB::beginTransaction();
DB::connection($boConn)->beginTransaction();   // antrian BO ada di koneksi lain — ikut dibatalkan

try {
    $kontrakId = (int) DB::table('fo_kontrak')->orderBy('id')->value('id');
    if (! $kontrakId) {
        throw new RuntimeException('tidak ada kontrak untuk dijadikan fikstur');
    }
    $pembeli = (string) DB::table('fo_kontrak')->join('fo_customers', 'fo_customers.id', '=', 'fo_kontrak.customer_id')
        ->where('fo_kontrak.id', $kontrakId)->value('fo_customers.full_name');
    $unit = (string) DB::table('fo_kontrak')->join('fo_units', 'fo_units.id', '=', 'fo_kontrak.unit_id')
        ->where('fo_kontrak.id', $kontrakId)->value('fo_units.kode_unit');

    $agentId = (int) DB::table('fo_agents')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-AG-1', 'name' => 'UJI Agen Komisi',
        'jenis' => 'EXTERNAL', 'is_arebi' => 0, 'komisi_persen_default' => 2.5, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $PENGAJUAN = 'UJI-KOMPENG-1';
    $LAIN      = 'UJI-KOMPENG-2';

    // [no_komisi, bruto, pph] — angka dari Excel KOMPENG-202609-00001.
    $isi = [
        ['UJI-KOM-1',  9346667.0, 186934.0],
        ['UJI-KOM-2', 11044792.0, 220896.0],
        ['UJI-KOM-3', 11044792.0, 220896.0],
    ];
    $buat = function (string $pengajuan, array $b) use ($tenantId, $kontrakId, $agentId) {
        [$no, $bruto, $pph] = $b;

        return Komisi::create([
            'tenant_id' => $tenantId, 'no_komisi' => $no, 'no_pengajuan' => $pengajuan,
            'tgl_komisi' => '2026-09-01', 'agent_id' => $agentId, 'kontrak_id' => $kontrakId,
            'dpp' => $bruto, 'persen_komisi' => 2.5, 'amount' => $bruto,
            'pph_persen' => 2.0, 'pph_amount' => $pph, 'ppn_amount' => 0,
            'amount_net' => $bruto - $pph,
            'status' => 'PAID', 'tgl_bayar' => '2026-09-01',
        ]);
    };
    foreach ($isi as $b) {
        $buat($PENGAJUAN, $b);
    }
    // Pengajuan lain — TIDAK boleh ikut terkirim.
    $buat($LAIN, ['UJI-KOM-9', 5000000.0, 100000.0]);

    /** Baris antrian BO milik pengajuan tertentu. */
    $antrian = fn (string $pengajuan) => DB::connection($boConn)->table('bs_ap_refund_queue')
        ->where('kind', 'komisi')->where('refund_no', 'like', $pengajuan.'#%')
        ->orderBy('id')->get()->all();

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Satu klik memindahkan SEMUA komisi di dalam pengajuannya ===\n";

    // Baris layar = hasil agregat GROUP BY, persis seperti yang diterima aksi tombolnya.
    $barisLayar = Komisi::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
        ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(amount) as total_komisi, SUM(pph_amount) as total_pph, SUM(amount_net) as total_nett, MAX(tgl_bayar) as tgl_bayar')
        ->groupBy('no_pengajuan')->having('no_pengajuan', '=', $PENGAJUAN)->first();
    $cek('layar meringkas '.count($isi).' komisi jadi satu baris pengajuan',
        (int) ($barisLayar->jml ?? 0) === count($isi), 'jml='.($barisLayar->jml ?? '?'));

    // Aksi tombolnya dijalankan apa adanya — closure diambil dari definisi tabel halamannya,
    // jadi yang diuji benar-benar kode yang jalan saat tombolnya diklik.
    $halaman = new \App\Filament\Fo\Pages\RealisasiKomisi();
    $tabel = $halaman->table(\Filament\Tables\Table::make($halaman));
    $aksi = collect($tabel->getFlatActions())->first(fn ($a) => $a->getName() === 'postingBo');
    $cek('tombol Posting BO ada pada Realisasi Komisi', $aksi !== null);
    $aksi->record($barisLayar)->call();

    $baris = $antrian($PENGAJUAN);
    $cek(count($isi).' komisi → '.count($isi).' baris antrian BO (tidak ada yang digabung/tertinggal)',
        count($baris) === count($isi), 'dapat '.count($baris).' baris');
    $cek('pengajuan lain tidak ikut terkirim', count($antrian($LAIN)) === 0);

    echo "\n=== 2. Isi tiap baris antrian lengkap ===\n";

    $perNo = [];
    foreach ($baris as $r) {
        $perNo[substr((string) $r->refund_no, strlen($PENGAJUAN) + 1)] = $r;
    }
    // MySQL menyimpan JSON sebagai objek dan MENYUSUN ULANG urutan kuncinya, jadi tiap entri
    // diurutkan dulu sebelum dibandingkan; nilainya disamakan tipenya karena JSON bilangan
    // bulat kembali sebagai int. Urutan ENTRI-nya tetap dibandingkan — itu yang menentukan
    // urutan baris di jurnalnya.
    $rapikan = function (array $a): array {
        foreach ($a as &$x) {
            if (is_array($x)) {
                if (isset($x['amount'])) {
                    $x['amount'] = round((float) $x['amount'], 2);
                }
                ksort($x);
            }
        }

        return $a;
    };
    foreach ($isi as $b) {
        [$no, $bruto, $pph] = $b;
        $r = $perNo[$no] ?? null;
        if (! $r) {
            $cek('antrian untuk '.$no.' ada', false, 'refund_no yang ada: '.implode(', ', array_keys($perNo)));

            continue;
        }
        $cek($no.': nilainya BRUTO (bukan nett)', round((float) $r->amount, 2) === $bruto, 'amount='.$r->amount);
        // Keterangan komisi TIDAK memuat kata jenjang — berbeda dari BLT, dan memang begitu
        // bentuknya pada permohonan komisi.
        $cek($no.': keterangan = "Komisi <pengajuan> <pembeli>, <unit>"',
            $r->keterangan === 'Komisi '.$PENGAJUAN.' '.$pembeli.', '.$unit, (string) $r->keterangan);
        $cek($no.': nama pembeli terbawa tersendiri', (string) $r->customer_name === $pembeli, (string) $r->customer_name);

        $split = json_decode((string) $r->account_split, true) ?: [];
        $cek($no.': rincian kredit = Utang Usaha (nett) + Hutang PPh 23 (potongan)',
            $rapikan($split) === $rapikan([
                ['side' => 'credit', 'role' => 'payable', 'code' => '20101010101', 'amount' => $bruto - $pph, 'label' => 'Hutang Usaha'],
                ['side' => 'credit', 'role' => 'pph', 'code' => '20104040403', 'amount' => $pph, 'label' => 'Hutang PPh 23'],
            ]),
            (string) $r->account_split);
        $cek($no.': jumlah rincian kredit = nilai antriannya',
            abs(array_sum(array_column($split, 'amount')) - (float) $r->amount) < 0.01);
    }

    echo "\n=== 3. Klik dua kali tidak menggandakan hutang ===\n";

    // Operator mengklik ulang karena ragu adalah hal biasa. Yang tidak boleh terjadi:
    // hutangnya bertambah dua kali lipat.
    $aksi->record($barisLayar)->call();
    $cek('tetap '.count($isi).' baris antrian setelah klik kedua',
        count($antrian($PENGAJUAN)) === count($isi), 'dapat '.count($antrian($PENGAJUAN)).' baris');

    echo "\n=== 4. Komisi tak wajar tidak dikirim diam-diam ===\n";

    $poster = app(IproApPoster::class);
    $cek('PPh melebihi nilainya ditolak',
        $poster->queueKomisiRow('UJI-KOMPENG-3', 'UJI-KOM-X', 1000000, 1500000, 'X', 'U-1') === null);
    $cek('nilai nol ditolak',
        $poster->queueKomisiRow('UJI-KOMPENG-3', 'UJI-KOM-Y', 0, 0, 'X', 'U-1') === null);

    echo "\n=== 5. Potongan dibawa apa adanya, bukan dihitung ulang 2% ===\n";

    // Persentase PPh komisi bisa berasal dari tarif per skema (KomisiCalculatorService), jadi
    // menghitung ulang dengan 2% akan meleset dari angka yang sudah dicetak di permohonannya.
    $idTarif = $poster->queueKomisiRow('UJI-KOMPENG-4', 'UJI-KOM-T', 10000000, 350000, 'PEMBELI T', 'U-7');
    $rt = DB::connection($boConn)->table('bs_ap_refund_queue')->where('id', $idTarif)->first();
    $splitT = json_decode((string) ($rt->account_split ?? ''), true) ?: [];
    $pphT = 0.0;
    $nettT = 0.0;
    foreach ($splitT as $x) {
        if (($x['role'] ?? '') === 'pph') {
            $pphT = round((float) $x['amount'], 2);
        }
        if (($x['role'] ?? '') === 'payable') {
            $nettT = round((float) $x['amount'], 2);
        }
    }
    $cek('PPh 3,5% dipakai apa adanya (bukan dipaksa 2%)', $pphT === 350000.0, 'pph='.$pphT);
    $cek('Utang Usaha = bruto − PPh yang dibawa', $nettT === 9650000.0, 'nett='.$nettT);

    echo "\n=== 6. Komisi tanpa potongan PPh tetap terkirim ===\n";

    $idTanpaPph = $poster->queueKomisiRow('UJI-KOMPENG-5', 'UJI-KOM-Z', 5000000, 0, 'PEMBELI Z', 'U-9');
    $cek('komisi tanpa PPh tetap masuk antrian', $idTanpaPph !== null);
    $rz = DB::connection($boConn)->table('bs_ap_refund_queue')->where('id', $idTanpaPph)->first();
    $splitZ = json_decode((string) ($rz->account_split ?? ''), true) ?: [];
    $cek('rincian kreditnya satu baris saja (tanpa baris PPh nol)', count($splitZ) === 1,
        (string) ($rz->account_split ?? ''));

    echo "\n=== 7. Komisi & BLT tidak saling menimpa di antrian ===\n";

    // Kunci antriannya (kind, refund_no). Bila kind-nya tertukar, komisi dan BLT dengan nomor
    // pengajuan mirip bisa saling menimpa dan salah satunya hilang tanpa galat apa pun.
    $cek('baris komisi tersimpan dengan kind=komisi',
        DB::connection($boConn)->table('bs_ap_refund_queue')
            ->where('refund_no', 'like', $PENGAJUAN.'#%')->where('kind', '!=', 'komisi')->count() === 0);

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::connection($boConn)->rollBack();
    DB::rollBack();
    echo "(data uji dibatalkan di kedua basis data)\n";
}

exit($gagal === 0 ? 0 : 1);
