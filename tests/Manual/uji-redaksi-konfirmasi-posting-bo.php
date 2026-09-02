<?php

declare(strict_types=1);

/*
 * Uji redaksi kotak konfirmasi tombol "Posting BO" — Realisasi BLT & Realisasi Komisi.
 *
 * Kalimat ini yang dibaca operator TEPAT SEBELUM hutang AP terbentuk, jadi bunyinya bukan
 * hiasan. Yang harus tersampaikan tiga hal, dan ketiganya diminta pemilik proses:
 *   1. nomor pengajuan yang sedang dikirim — supaya tidak salah batch;
 *   2. tiap BLT/komisi menjadi baris tersendiri, tidak ada yang digabung;
 *   3. ini AKAN menjadi hutang AP setelah diposting di BO.
 *
 * Angka jumlah/nilai/PPh/NETT sengaja tidak lagi diulang di kalimat ini: keempatnya sudah
 * terbaca pada baris yang tombolnya baru saja diklik.
 *
 * NOMOR PENGAJUANNYA DIUJI BERGANTI-GANTI. Kalimat yang benar untuk satu batch tetapi
 * memuat nomor yang dipaku akan mengirim operator memposting batch yang salah tanpa satu pun
 * galat — persis kesalahan yang paling mahal di layar ini.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec jerp-verify php tests/Manual/uji-redaksi-konfirmasi-posting-bo.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\FO\Komisi\Models\Blt;
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

$tenantId = (int) (DB::table('fo_kontrak')->value('tenant_id') ?: 1);
app()->instance('tenant.id', $tenantId);

DB::beginTransaction();

try {
    $kontrakId = (int) DB::table('fo_kontrak')->orderBy('id')->value('id');
    if (! $kontrakId) {
        throw new RuntimeException('tidak ada kontrak untuk dijadikan fikstur');
    }
    $agentId = (int) DB::table('fo_agents')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-AG-R', 'name' => 'UJI Agen Redaksi',
        'jenis' => 'EXTERNAL', 'is_arebi' => 0, 'komisi_persen_default' => 2.5, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /** Ambil kalimat konfirmasi aksi postingBo dari halamannya, untuk satu baris agregat. */
    $redaksi = function (string $kelasHalaman, object $baris): array {
        $halaman = new $kelasHalaman();
        $tabel = $halaman->table(\Filament\Tables\Table::make($halaman));
        $aksi = collect($tabel->getFlatActions())->first(fn ($a) => $a->getName() === 'postingBo');
        if (! $aksi) {
            return ['judul' => null, 'isi' => null];
        }
        $aksi = $aksi->record($baris);

        return ['judul' => $aksi->getModalHeading(), 'isi' => $aksi->getModalDescription()];
    };

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Realisasi BLT ===\n";

    // DUA pengajuan dengan isi berbeda — nomornya harus ikut berganti di kalimatnya.
    foreach ([['UJI-BLTPENG-A', 1, 25000000.0, 625000.0], ['UJI-BLTPENG-B', 3, 30000000.0, 737500.0]] as [$peng, $jml, $bruto, $pph]) {
        for ($i = 1; $i <= $jml; $i++) {
            Blt::create([
                'tenant_id' => $tenantId, 'no_blt' => $peng.'-'.$i, 'no_pengajuan' => $peng,
                'tgl_blt' => '2026-09-02', 'kontrak_id' => $kontrakId, 'level_type' => 'SALES',
                'nilai_blt' => $bruto / $jml, 'pph_jenis' => 'PPh21', 'pph_persen' => 2.5,
                'pph_amount' => $pph / $jml, 'ppn_amount' => 0, 'nett' => ($bruto - $pph) / $jml,
                'status' => 'PAID', 'tgl_bayar' => '2026-09-02',
            ]);
        }
    }

    $barisBlt = fn (string $peng) => Blt::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
        ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(nilai_blt) as total_nilai, SUM(pph_amount) as total_pph, SUM(nett) as total_nett, MAX(tgl_bayar) as tgl_bayar')
        ->groupBy('no_pengajuan')->having('no_pengajuan', '=', $peng)->first();

    foreach (['UJI-BLTPENG-A', 'UJI-BLTPENG-B'] as $peng) {
        $r = $redaksi(\App\Filament\Fo\Pages\RealisasiBlt::class, $barisBlt($peng));
        $mau = 'Kirim BLT pada '.$peng.' ke Posting Invoice BO. Setiap BLT menjadi satu baris tersendiri dan tidak ada yang digabung. Akan menjadi hutang AP setelah diposting di BO.';
        $cek($peng.': kalimatnya sama persis', $r['isi'] === $mau, "\n        dapat   : ".var_export($r['isi'], true)."\n        harusnya: ".var_export($mau, true));
    }

    $rA = $redaksi(\App\Filament\Fo\Pages\RealisasiBlt::class, $barisBlt('UJI-BLTPENG-A'));
    $cek('judulnya tetap "Posting BLT ke BO"', $rA['judul'] === 'Posting BLT ke BO', (string) $rA['judul']);
    $cek('tidak lagi menyebut kata "batch"', ! str_contains((string) $rA['isi'], 'batch'), (string) $rA['isi']);
    $cek('tidak lagi mengulang nilai rupiah', ! str_contains((string) $rA['isi'], 'Rp '), (string) $rA['isi']);
    $cek('memakai "dan tidak ada yang digabung", bukan tanda pisah',
        str_contains((string) $rA['isi'], 'dan tidak ada yang digabung') && ! str_contains((string) $rA['isi'], '—'),
        (string) $rA['isi']);
    $cek('memakai "Akan menjadi hutang AP"', str_contains((string) $rA['isi'], 'Akan menjadi hutang AP'), (string) $rA['isi']);

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 2. Realisasi Komisi ===\n";

    foreach ([['UJI-KOMPENG-A', 1, 9346667.0, 186934.0], ['UJI-KOMPENG-B', 2, 14225000.0, 284500.0]] as [$peng, $jml, $bruto, $pph]) {
        for ($i = 1; $i <= $jml; $i++) {
            Komisi::create([
                'tenant_id' => $tenantId, 'no_komisi' => $peng.'-'.$i, 'no_pengajuan' => $peng,
                'tgl_komisi' => '2026-09-02', 'agent_id' => $agentId, 'kontrak_id' => $kontrakId,
                'dpp' => $bruto / $jml, 'persen_komisi' => 2.5, 'amount' => $bruto / $jml,
                'pph_persen' => 2.0, 'pph_amount' => $pph / $jml, 'ppn_amount' => 0,
                'amount_net' => ($bruto - $pph) / $jml, 'status' => 'PAID', 'tgl_bayar' => '2026-09-02',
            ]);
        }
    }

    $barisKom = fn (string $peng) => Komisi::query()->whereNotNull('no_pengajuan')->where('status', 'PAID')
        ->selectRaw('MIN(id) as id, no_pengajuan, COUNT(*) as jml, SUM(amount) as total_komisi, SUM(pph_amount) as total_pph, SUM(amount_net) as total_nett, MAX(tgl_bayar) as tgl_bayar')
        ->groupBy('no_pengajuan')->having('no_pengajuan', '=', $peng)->first();

    foreach (['UJI-KOMPENG-A', 'UJI-KOMPENG-B'] as $peng) {
        $r = $redaksi(\App\Filament\Fo\Pages\RealisasiKomisi::class, $barisKom($peng));
        $mau = 'Kirim komisi pada '.$peng.' ke Posting Invoice BO. Setiap komisi menjadi satu baris tersendiri dan tidak ada yang digabung. Akan menjadi hutang AP setelah diposting di BO.';
        $cek($peng.': kalimatnya sama persis', $r['isi'] === $mau, "\n        dapat   : ".var_export($r['isi'], true)."\n        harusnya: ".var_export($mau, true));
    }

    $rK = $redaksi(\App\Filament\Fo\Pages\RealisasiKomisi::class, $barisKom('UJI-KOMPENG-A'));
    $cek('judulnya tetap "Posting Komisi ke BO"', $rK['judul'] === 'Posting Komisi ke BO', (string) $rK['judul']);
    $cek('tidak lagi menyebut kata "batch"', ! str_contains((string) $rK['isi'], 'batch'), (string) $rK['isi']);
    $cek('tidak lagi mengulang nilai rupiah', ! str_contains((string) $rK['isi'], 'Rp '), (string) $rK['isi']);

    echo "\n=== 3. Nomor pengajuannya benar-benar mengikuti barisnya ===\n";

    // Kalimat yang memaku satu nomor akan tetap lulus pemeriksaan di atas bila kebetulan
    // nomor itu yang diuji. Yang membuktikan sebaliknya: dua kalimat harus BERBEDA.
    $a = $redaksi(\App\Filament\Fo\Pages\RealisasiBlt::class, $barisBlt('UJI-BLTPENG-A'))['isi'];
    $b = $redaksi(\App\Filament\Fo\Pages\RealisasiBlt::class, $barisBlt('UJI-BLTPENG-B'))['isi'];
    $cek('BLT: dua pengajuan menghasilkan kalimat berbeda', $a !== $b);
    $cek('BLT: masing-masing memuat nomornya sendiri',
        str_contains((string) $a, 'UJI-BLTPENG-A') && ! str_contains((string) $a, 'UJI-BLTPENG-B'));

    $c = $redaksi(\App\Filament\Fo\Pages\RealisasiKomisi::class, $barisKom('UJI-KOMPENG-A'))['isi'];
    $e = $redaksi(\App\Filament\Fo\Pages\RealisasiKomisi::class, $barisKom('UJI-KOMPENG-B'))['isi'];
    $cek('Komisi: dua pengajuan menghasilkan kalimat berbeda', $c !== $e);
    $cek('Komisi: masing-masing memuat nomornya sendiri',
        str_contains((string) $c, 'UJI-KOMPENG-A') && ! str_contains((string) $c, 'UJI-KOMPENG-B'));

    echo "\n=== 4. Tombolnya tetap meminta konfirmasi ===\n";

    // Redaksi sebagus apa pun tak ada gunanya bila dialognya tidak muncul. Filament tidak
    // menyimpan penanda "butuh konfirmasi"; requiresConfirmation() memasang ikon peringatan
    // dan label tombol kirimnya — dua jejak itulah yang diperiksa di sini.
    foreach ([[\App\Filament\Fo\Pages\RealisasiBlt::class, 'BLT'], [\App\Filament\Fo\Pages\RealisasiKomisi::class, 'Komisi']] as [$kelas, $label]) {
        $halaman = new $kelas();
        $tabel = $halaman->table(\Filament\Tables\Table::make($halaman));
        $aksi = collect($tabel->getFlatActions())->first(fn ($a) => $a->getName() === 'postingBo');
        $cek($label.': Posting BO masih memerlukan konfirmasi',
            $aksi !== null && $aksi->getModalIcon() !== null && $aksi->getModalSubmitActionLabel() !== null,
            'ikon='.var_export($aksi?->getModalIcon(), true).' tombol='.var_export($aksi?->getModalSubmitActionLabel(), true));
    }

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
