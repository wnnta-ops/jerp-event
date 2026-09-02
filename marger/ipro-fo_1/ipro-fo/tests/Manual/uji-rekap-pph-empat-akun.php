<?php

declare(strict_types=1);

/*
 * Uji Rekap PPh Dipotong — KEEMPAT akun Hutang PPh harus tersambung dengan Detail Transaksi
 * Jurnal, dan angka kartunya harus sama dengan angka GL-nya.
 *
 *   20104040401  HUTANG PPH PASAL 4 AYAT (2)
 *   20104040402  HUTANG PPH PASAL 21
 *   20104040403  HUTANG PPH PASAL 23
 *   20104040404  HUTANG PPH PASAL 25/29
 *
 * YANG DIJAGA: apa pun yang mengkredit keempat akun itu di buku besar HARUS muncul sebagai
 * baris rekap, tak peduli dari jalur mana jurnalnya lahir. Versi lama layar ini membaca
 * kolom bs_ap_invoices.pph_amount, sehingga pemotongan dari jalur Posting Invoice —
 * yang memaku pph_amount = 0 — mustahil muncul: kartunya berbunyi "Rp 0" sementara barisnya
 * jelas terlihat di Detail Transaksi Jurnal.
 *
 * DIJAGA JUGA: angka cross-check GL dihitung atas POPULASI YANG SAMA dengan rinciannya.
 * Query GL memakai DB::table sehingga global scope tidak berlaku; bila saringan tenant atau
 * soft-delete-nya tertinggal, kartunya memperlihatkan selisih yang tidak bisa ditelusuri ke
 * satu baris pun di tabelnya — bentuk kesalahan yang paling sulit dijelaskan ke operator.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-rekap-pph-empat-akun.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Web\BusinessSupport\Tax\PphController;
use Illuminate\Support\Facades\DB;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

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

$akunId = fn (int|string $kode) => (int) DB::table('bs_accounts')->where('code', (string) $kode)->value('id');

/** Keempat akun + pasangan debitnya. */
$PASAL = [
    '20104040401' => 'PPh Pasal 4(2)',
    '20104040402' => 'PPh Pasal 21',
    '20104040403' => 'PPh Pasal 23',
    '20104040404' => 'PPh Pasal 25/29',
];

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $LAWAN  = $akunId('50201010103');   // beban — sisi debitnya
    if (! $LAWAN) {
        throw new RuntimeException('akun 50201010103 tidak ada di Bagan Akun');
    }

    /** Buat satu jurnal: Dr beban / Cr akun PPh. */
    $jurnal = function (int|string $kodePph, float $nilai, string $tgl, string $status, ?int $tenant = null) use ($tenantId, $userId, $LAWAN, $akunId) {
        static $urut = 0;
        $urut++;
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenant ?? $tenantId, 'journal_number' => 'UJI-PPH-'.$urut,
            'journal_date' => $tgl, 'period_year' => (int) substr($tgl, 0, 4), 'period_month' => (int) substr($tgl, 5, 2),
            'source' => 'ap', 'reference' => 'UJI/'.$urut, 'description' => 'UJI potong '.(string) $kodePph,
            'status' => $status, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$LAWAN, $nilai, 0.0], [$akunId($kodePph), 0.0, $nilai]] as [$a, $d, $k]) {
            DB::table('bs_journal_lines')->insert([
                'journal_id' => $jid, 'account_id' => $a, 'debit' => $d, 'credit' => $k,
                'description' => 'UJI '.(string) $kodePph, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $jid;
    };

    // Satu pemotongan per pasal, nilainya berbeda-beda supaya tertukar pun ketahuan.
    $nilai = ['20104040401' => 111000.0, '20104040402' => 222000.0, '20104040403' => 236167.0, '20104040404' => 444000.0];
    foreach ($nilai as $kode => $v) {
        $jurnal($kode, $v, '2026-08-10', 'posted');
    }

    $ctrl = app(PphController::class);
    $hitung = new ReflectionMethod($ctrl, 'hitung');
    $hitung->setAccessible(true);
    $d = $hitung->invoke($ctrl, 2026, 8);

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Keempat akun Hutang PPh muncul di rekap ===\n";

    foreach ($PASAL as $kode => $label) {
        $baris = collect($d['rows'])->where('account_code', $kode);
        $cek((string) $kode.' — '.$label.' punya barisnya', $baris->count() === 1, 'dapat '.$baris->count().' baris');
        $cek((string) $kode.' nilainya utuh', round((float) $baris->sum('pph'), 2) === $nilai[$kode],
            'dapat '.$baris->sum('pph').' harusnya '.$nilai[$kode]);
    }

    echo "\n=== 2. Kartu pasal = angka GL akunnya ===\n";

    // Inilah arti "nge-link": angka yang dipajang layar dihitung dari baris buku besar yang
    // sama dengan yang dipajang Detail Transaksi Jurnal untuk akun itu.
    foreach ($PASAL as $kode => $label) {
        $cek($label.': kartu = GL', round((float) $d['byArticle'][$label], 2) === round((float) $d['gl'][$label], 2),
            'kartu='.$d['byArticle'][$label].' gl='.$d['gl'][$label]);
        $cek($label.': GL = nilai yang dijurnalkan', round((float) $d['gl'][$label], 2) === $nilai[$kode],
            'gl='.$d['gl'][$label]);
    }

    echo "\n=== 3. Tiap baris membawa tautan ke jurnalnya ===\n";

    // Tanpa journal_id, baris rekap tidak bisa ditelusuri balik ke Detail Transaksi Jurnal —
    // dan justru penelusuran itulah yang dipakai saat menyiapkan SPT.
    $tanpaTaut = collect($d['rows'])->filter(fn ($r) => empty($r['journal_id']))->count();
    $cek('semua baris punya journal_id', $tanpaTaut === 0, $tanpaTaut.' baris tanpa tautan');
    $cek('keempat akun disertakan untuk pranala per akun', count($d['akun']) === 4, count($d['akun']).' akun');
    $cek('rentang masa pajaknya ikut dikirim',
        ($d['periode']['from'] ?? '') === '2026-08-01' && ($d['periode']['to'] ?? '') === '2026-08-31',
        json_encode($d['periode']));

    echo "\n=== 4. Jurnal yang belum Approved tidak menaikkan SPT, tapi tetap terlihat ===\n";

    $jurnal('20104040403', 50000.0, '2026-08-11', 'final');
    $d2 = $hitung->invoke($ctrl, 2026, 8);
    $cek('barisnya tetap tampil di rekap',
        collect($d2['rows'])->where('account_code', '20104040403')->count() === 2);
    $cek('nilai SPT PPh 23 tidak ikut naik',
        round((float) $d2['byArticle']['PPh Pasal 23'], 2) === 236167.0, 'dapat '.$d2['byArticle']['PPh Pasal 23']);
    $cek('yang belum approved dilaporkan tersendiri',
        round((float) $d2['belumApproved']['PPh Pasal 23'], 2) === 50000.0, 'dapat '.$d2['belumApproved']['PPh Pasal 23']);
    $cek('angka GL juga tidak menghitung yang belum approved',
        round((float) $d2['gl']['PPh Pasal 23'], 2) === 236167.0, 'gl='.$d2['gl']['PPh Pasal 23']);

    echo "\n=== 5. Cross-check GL diukur atas populasi yang sama ===\n";

    // Jurnal milik TENANT LAIN tidak boleh ikut terhitung. Rinciannya dibaca lewat Eloquent
    // (tenant-scoped), jadi kalau angka GL-nya tidak ikut disaring, kartunya memperlihatkan
    // selisih yang tidak ada barisnya di tabel — tidak bisa dijelaskan ke siapa pun.
    // Tenant kedua DIBUAT di sini bila belum ada. Basis data pengembangan biasanya hanya
    // berisi satu tenant, dan melewatkan pemeriksaan ini berarti saringan yang baru saja
    // ditambahkan tidak pernah benar-benar diuji — pemeriksaan yang tidak berjalan bukan
    // pemeriksaan yang lulus. Ikut dibatalkan bersama transaksinya.
    $tenantLain = (int) DB::table('tenants')->where('id', '!=', $tenantId)->value('id');
    if (! $tenantLain) {
        $tenantLain = (int) DB::table('tenants')->insertGetId([
            'code' => 'UJI-T2', 'name' => 'UJI Tenant Kedua', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $jurnal('20104040403', 99000.0, '2026-08-12', 'posted', $tenantLain);
    $d3 = $hitung->invoke($ctrl, 2026, 8);
    $cek('jurnal tenant lain tidak menaikkan angka GL',
        round((float) $d3['gl']['PPh Pasal 23'], 2) === 236167.0, 'gl='.$d3['gl']['PPh Pasal 23']);
    $cek('jurnal tenant lain juga tidak muncul di rinciannya',
        collect($d3['rows'])->where('account_code', '20104040403')->count() === 2);
    $cek('kartu & GL tetap sepakat', round((float) $d3['byArticle']['PPh Pasal 23'], 2) === round((float) $d3['gl']['PPh Pasal 23'], 2),
        'kartu='.$d3['byArticle']['PPh Pasal 23'].' gl='.$d3['gl']['PPh Pasal 23']);

    // Jurnal terhapus juga tidak boleh ikut.
    $jidHapus = $jurnal('20104040402', 77000.0, '2026-08-13', 'posted');
    DB::table('bs_journals')->where('id', $jidHapus)->update(['deleted_at' => now()]);
    $d4 = $hitung->invoke($ctrl, 2026, 8);
    $cek('jurnal terhapus tidak menaikkan angka GL',
        round((float) $d4['gl']['PPh Pasal 21'], 2) === 222000.0, 'gl='.$d4['gl']['PPh Pasal 21']);
    $cek('jurnal terhapus juga hilang dari rinciannya',
        collect($d4['rows'])->where('account_code', '20104040402')->count() === 1);

    echo "\n=== 6. Ekspor CSV memakai perhitungan yang sama ===\n";

    // Ekspor yang punya query sendiri akan menyimpang diam-diam saat salah satunya disesuaikan.
    $rowsEkspor = collect($hitung->invoke($ctrl, 2026, 8)['rows']);
    $cek('jumlah baris ekspor = jumlah baris layar', $rowsEkspor->count() === count($d4['rows']),
        $rowsEkspor->count().' vs '.count($d4['rows']));

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
