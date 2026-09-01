<?php

declare(strict_types=1);

/*
 * Uji tombol "Posting BO" pada Realisasi BLT (sisi FO).
 *
 * Baris pada layar Realisasi BLT adalah hasil GROUP BY no_pengajuan — satu baris mewakili
 * SELURUH isi pengajuan. Sebelumnya tombolnya ikut memperlakukan batch itu sebagai satu
 * kesatuan: yang dikirim ke BO satu baris berisi TOTAL NETT, dengan nama pembelinya
 * diringkas jadi satu untai. Tiga BLT untuk dua pembeli berakhir sebagai satu invoice
 * gelondongan — tidak terbaca milik siapa, dan tidak bisa dibayarkan ke rekening
 * masing-masing penerima.
 *
 * Yang dijaga di sini: SATU KLIK = SEMUA BLT DI DALAMNYA PINDAH, satu per satu, lengkap
 * dengan nilai bruto, potongan PPh, nama pembeli, dan blok/kavlingnya. Pengajuan lain
 * tidak boleh ikut terbawa.
 *
 * Bentuk jurnal yang dihasilkannya di BO diuji di repo ipro-fo:
 * tests/Manual/uji-blt-posting-jurnal.php
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — di kedua basis data.
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec jerp-verify php tests/Manual/uji-blt-antre-per-baris.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\BO\IproBridge\Services\IproApPoster;
use App\Modules\FO\Komisi\Models\Blt;
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
$tenantId = (int) (Blt::query()->value('tenant_id') ?: 1);
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

    $PENGAJUAN = 'UJI-BLTPENG-1';
    $LAIN      = 'UJI-BLTPENG-2';

    // [no_blt, level_type, bruto, pph]
    $isi = [
        ['UJI-BLT-1', 'SALES',       25000000.0, 625000.0],
        ['UJI-BLT-2', 'SALES',       25000000.0, 625000.0],
        ['UJI-BLT-3', 'KOORDINATOR',  2500000.0,  62500.0],
    ];
    $buat = function (string $pengajuan, array $b) use ($tenantId, $kontrakId) {
        [$no, $level, $bruto, $pph] = $b;

        return Blt::create([
            'tenant_id' => $tenantId, 'no_blt' => $no, 'no_pengajuan' => $pengajuan,
            'tgl_blt' => '2026-09-01', 'kontrak_id' => $kontrakId, 'level_type' => $level,
            'nilai_blt' => $bruto, 'pph_jenis' => 'PPh21', 'pph_persen' => 2.5,
            'pph_amount' => $pph, 'ppn_amount' => 0, 'nett' => $bruto - $pph,
            'status' => 'PAID', 'tgl_bayar' => '2026-09-01',
        ]);
    };
    foreach ($isi as $b) {
        $buat($PENGAJUAN, $b);
    }
    // Pengajuan lain — TIDAK boleh ikut terkirim.
    $buat($LAIN, ['UJI-BLT-9', 'SALES', 9000000.0, 225000.0]);

    /** Baris antrian BO milik pengajuan tertentu. */
    $antrian = fn (string $pengajuan) => DB::connection($boConn)->table('bs_ap_refund_queue')
        ->where('kind', 'blt')->where('refund_no', 'like', $pengajuan.'#%')
        ->orderBy('id')->get()->all();

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Satu klik memindahkan SEMUA BLT di dalam pengajuannya ===\n";

    // Baris layar = hasil agregat GROUP BY, persis seperti yang diterima aksi tombolnya.
    $barisLayar = Blt::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
        ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(nilai_blt) as total_nilai, SUM(pph_amount) as total_pph, SUM(nett) as total_nett, MAX(tgl_bayar) as tgl_bayar')
        ->groupBy('no_pengajuan')->having('no_pengajuan', '=', $PENGAJUAN)->first();
    $cek('layar meringkas 3 BLT jadi satu baris pengajuan', (int) ($barisLayar->jml ?? 0) === 3,
        'jml='.($barisLayar->jml ?? '?'));

    // Aksi tombolnya dijalankan apa adanya — closure diambil dari definisi tabel halamannya,
    // jadi yang diuji benar-benar kode yang jalan saat tombolnya diklik.
    $halaman = new \App\Filament\Fo\Pages\RealisasiBlt();
    $tabel = $halaman->table(\Filament\Tables\Table::make($halaman));
    $aksi = collect($tabel->getFlatActions())->first(fn ($a) => $a->getName() === 'postingBo');
    $cek('tombol Posting BO ada pada Realisasi BLT', $aksi !== null);
    $aksi->record($barisLayar)->call();

    $baris = $antrian($PENGAJUAN);
    $cek('3 BLT → 3 baris antrian BO (tidak ada yang digabung/tertinggal)', count($baris) === 3,
        'dapat '.count($baris).' baris');
    $cek('pengajuan lain tidak ikut terkirim', count($antrian($LAIN)) === 0);

    echo "\n=== 2. Isi tiap baris antrian lengkap ===\n";

    $perNo = [];
    foreach ($baris as $r) {
        $perNo[substr((string) $r->refund_no, strlen($PENGAJUAN) + 1)] = $r;
    }
    foreach ($isi as $b) {
        [$no, $level, $bruto, $pph] = $b;
        $r = $perNo[$no] ?? null;
        if (! $r) {
            $cek('antrian untuk '.$no.' ada', false, 'refund_no yang ada: '.implode(', ', array_keys($perNo)));

            continue;
        }
        $jenis = ucfirst(strtolower($level));
        $cek($no.': nilainya BRUTO (bukan nett)', round((float) $r->amount, 2) === $bruto, 'amount='.$r->amount);
        $cek($no.': keterangan memuat jenis, pengajuan, pembeli, & unit',
            $r->keterangan === 'BLT '.$jenis.' '.$PENGAJUAN.' '.$pembeli.', '.$unit, (string) $r->keterangan);
        $cek($no.': nama pembeli terbawa tersendiri', (string) $r->customer_name === $pembeli, (string) $r->customer_name);

        // MySQL menyimpan JSON sebagai objek dan MENYUSUN ULANG urutan kuncinya, jadi tiap
        // entri diurutkan dulu sebelum dibandingkan. Urutan ENTRI-nya tetap dibandingkan —
        // itu yang menentukan urutan baris di jurnalnya.
        // Nilainya juga disamakan tipenya: JSON bilangan bulat kembali sebagai int, sedangkan
        // harapan uji ditulis sebagai pecahan.
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
    $cek('tetap 3 baris antrian setelah klik kedua', count($antrian($PENGAJUAN)) === 3,
        'dapat '.count($antrian($PENGAJUAN)).' baris');

    echo "\n=== 4. BLT tak wajar tidak dikirim diam-diam ===\n";

    $poster = app(IproApPoster::class);
    $cek('PPh melebihi nilainya ditolak',
        $poster->queueBltRow('UJI-BLTPENG-3', 'UJI-BLT-X', 'Sales', 1000000, 1500000, 'X', 'U-1') === null);
    $cek('nilai nol ditolak',
        $poster->queueBltRow('UJI-BLTPENG-3', 'UJI-BLT-Y', 'Sales', 0, 0, 'X', 'U-1') === null);

    echo "\n=== 5. BLT tanpa potongan PPh tetap terkirim ===\n";

    $idTanpaPph = $poster->queueBltRow('UJI-BLTPENG-4', 'UJI-BLT-Z', 'Kantor', 5000000, 0, 'PEMBELI Z', 'U-9');
    $cek('BLT tanpa PPh tetap masuk antrian', $idTanpaPph !== null);
    $rz = DB::connection($boConn)->table('bs_ap_refund_queue')->where('id', $idTanpaPph)->first();
    $splitZ = json_decode((string) ($rz->account_split ?? ''), true) ?: [];
    $cek('rincian kreditnya satu baris saja (tanpa baris PPh nol)', count($splitZ) === 1,
        (string) ($rz->account_split ?? ''));

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
