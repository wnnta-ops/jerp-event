<?php

declare(strict_types=1);

/*
 * Uji import massal Supplier & Karyawan.
 *
 * DUA hal yang dijaga, dan keduanya gagal TANPA GALAT bila rusak:
 *
 * 1. Baris yang bermasalah harus DILAPORKAN, bukan dibuang diam-diam. Import yang berkata
 *    "120 supplier diproses" atas berkas 200 baris menyembunyikan 80 kegagalan, dan tak
 *    seorang pun akan mencarinya.
 * 2. Berkas yang hanya memuat sebagian kolom TIDAK boleh menimpa kolom lain dengan kosong.
 *    Berkas dua kolom untuk membetulkan ejaan nama tidak boleh menghapus NPWP & rekening
 *    yang sudah tersimpan — dan kalau itu terjadi, layarnya tetap berkata "berhasil".
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — basis data kembali seperti semula.
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-import-massal.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Hrd\EmployeeController;
use App\Http\Controllers\Web\BusinessSupport\Setup\SupplierController;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

$tmp = sys_get_temp_dir().'/uji-impor';
if (! is_dir($tmp)) {
    mkdir($tmp, 0777, true);
}
$sampah = [];

/** Tulis matriks jadi .xlsx sungguhan lalu bungkus sebagai berkas terunggah. */
$xlsx = function (array $rows, string $nama) use ($tmp, &$sampah): UploadedFile {
    $p = $tmp.'/'.$nama.'.xlsx';
    file_put_contents($p, SimpleXlsx::toString($rows));
    $sampah[] = $p;

    return new UploadedFile($p, $nama.'.xlsx', null, null, true);
};

/** Tulis CSV — termasuk BOM & pemisah titik-koma ala Excel Indonesia bila diminta. */
$csv = function (array $rows, string $nama, string $pemisah = ',', bool $bom = false) use ($tmp, &$sampah): UploadedFile {
    $p = $tmp.'/'.$nama.'.csv';
    $fh = fopen($p, 'w');
    if ($bom) {
        fwrite($fh, "\xEF\xBB\xBF");
    }
    foreach ($rows as $r) {
        fputcsv($fh, $r, $pemisah);
    }
    fclose($fh);
    $sampah[] = $p;

    return new UploadedFile($p, $nama.'.csv', null, null, true);
};

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    $cek('user uji berhak mengimpor', (bool) $user?->can('businesssupport.setup.manage'),
        'user '.$userId.' tidak punya izin businesssupport.setup.manage');

    /** Panggil satu aksi controller dengan berkas + mode, kembalikan hasil flash-nya. */
    $impor = function (object $ctl, UploadedFile $file, string $mode = 'perbarui') use ($user): array {
        $req = Request::create('/x', 'POST', ['mode' => $mode], [], ['file' => $file]);
        $req->setUserResolver(fn () => $user);
        $resp = $ctl->import($req);
        $sesi = $resp->getSession();

        return [
            'import'  => $sesi?->get('import') ?? [],
            'success' => $sesi?->get('success'),
            'warning' => $sesi?->get('warning'),
            'error'   => $sesi?->get('error'),
        ];
    };

    $supCtl = app(SupplierController::class);
    $empCtl = app(EmployeeController::class);

    // ── 0. Pencocokan judul kolom ────────────────────────────────────────────────────────────
    echo "\n=== 0. Pencocokan judul kolom ===\n";

    // Diuji langsung karena dua mekanismenya sulit dipicu lewat berkas nyata, sementara
    // keduanya salah TANPA GALAT bila rusak: berkasnya terimpor, isinya saja yang tertukar.
    $K = \App\Support\Import\TabelImpor::class;

    // (a) Kata kunci yang lebih spesifik menang atas yang longgar, walau kolomnya di belakang.
    $cek('kunci spesifik mengalahkan kunci longgar di kolom depan',
        $K::kolom(['VENDOR', 'NAMA VENDOR'], ['NAMA VENDOR', 'VENDOR']) === 1,
        json_encode($K::kolom(['VENDOR', 'NAMA VENDOR'], ['NAMA VENDOR', 'VENDOR'])));

    // (b) Kolom yang sudah diklaim field lain tidak boleh dipakai dua kali.
    $cek('kolom yang sudah diklaim dilewati',
        $K::kolom(['VENDOR', 'NAMA VENDOR'], ['NAMA VENDOR', 'VENDOR'], [], [1 => true]) === 0,
        json_encode($K::kolom(['VENDOR', 'NAMA VENDOR'], ['NAMA VENDOR', 'VENDOR'], [], [1 => true])));

    // (c) BOM UTF-8 dari Excel dibuang saat membaca CSV. Pencocokan substring kebetulan
    //     memaafkannya, jadi kerusakannya tidak terlihat lewat hasil import — tetapi nilai
    //     selnya ikut membawa BOM, dan judul kolom yang dibandingkan persis akan meleset.
    $pBom = $tmp.'/bom.csv';
    // BOM ditulis lewat chr() supaya isinya pasti TIGA byte EF BB BF — menuliskan karakternya
    // langsung di dalam berkas ini menghasilkan enam byte (BOM yang ter-encode dua kali), dan
    // ujinya lalu menuduh pembacanya rusak padahal fikturnya yang keliru.
    $bom = chr(0xEF).chr(0xBB).chr(0xBF);
    file_put_contents($pBom, $bom."Kode,Nama Supplier\nX,Y\n");
    $sampah[] = $pBom;
    $isiBom = $K::baca(new UploadedFile($pBom, 'bom.csv', null, null, true));
    $cek('BOM UTF-8 dibuang dari judul kolom pertama',
        ($isiBom[0][0] ?? '') === 'Kode', json_encode($isiBom[0][0] ?? null));

    // (d) Pengecualian membuang kolom yang jelas-jelas bukan yang dimaksud.
    $cek('"KODE SUPPLIER" tidak cocok sebagai kolom nama',
        $K::kolom(['KODE SUPPLIER'], ['SUPPLIER'], ['KODE']) === null,
        json_encode($K::kolom(['KODE SUPPLIER'], ['SUPPLIER'], ['KODE'])));

    // (e) Angka gaya Indonesia & Inggris dua-duanya terbaca; menebak salah satu saja membuat
    //     gaji 7 juta terbaca 7 rupiah.
    foreach ([['7.500.000', 7500000.0], ['7,500,000', 7500000.0], ['7500000,50', 7500000.5],
        ['7500000.50', 7500000.5], ['Rp 1.250.000', 1250000.0], ['abc', null]] as [$raw, $harap]) {
        $cek('angka "'.$raw.'" terbaca '.json_encode($harap), $K::angka($raw) === $harap, json_encode($K::angka($raw)));
    }

    // ── 1. Daftar kolom di layar = daftar kolom yang dikenali server ─────────────────────────
    echo "\n=== 1. Layar & server sepakat soal kolomnya ===\n";

    $judulDari = function (string $kelas): array {
        $r = new ReflectionClass($kelas);
        return array_map(fn (array $k) => $k[0], array_values($r->getConstant('KOLOM')));
    };
    $dariVue = function (string $berkas): array {
        $isi = file_get_contents(__DIR__.'/../../'.$berkas);
        preg_match('/const KOLOM_IMPOR = \[(.*?)\];/s', (string) $isi, $m);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1] ?? '', $mm);

        return array_map(fn ($s) => str_replace("\\'", "'", $s), $mm[1] ?? []);
    };

    // Keterangan di layar menjanjikan kolom apa saja yang dikenali. Kalau daftarnya berbeda
    // dari yang dibaca server, layar berbohong tanpa satu pun galat.
    $cek('kolom Supplier di layar sama dengan di controller',
        $dariVue('resources/js/Pages/BusinessSupport/Setup/Suppliers.vue') === $judulDari(SupplierController::class),
        json_encode($dariVue('resources/js/Pages/BusinessSupport/Setup/Suppliers.vue')));
    $cek('kolom Karyawan di layar sama dengan di controller',
        $dariVue('resources/js/Pages/BusinessSupport/Hrd/Employees.vue') === $judulDari(EmployeeController::class),
        json_encode($dariVue('resources/js/Pages/BusinessSupport/Hrd/Employees.vue')));

    // ── 2. Template terunduh memakai judul yang sama ─────────────────────────────────────────
    echo "\n=== 2. Template yang diunduh bisa langsung dipakai ===\n";

    foreach ([['Supplier', $supCtl, SupplierController::class], ['Karyawan', $empCtl, EmployeeController::class]] as [$nama, $ctl, $kelas]) {
        $req = Request::create('/x', 'GET');
        $req->setUserResolver(fn () => $user);
        $bin = $ctl->importTemplate($req)->getContent();
        $p = $tmp.'/tpl-'.$nama.'.xlsx';
        file_put_contents($p, $bin);
        $sampah[] = $p;
        $baris = SimpleXlsx::rows($p);
        $cek("template {$nama} judulnya sama dengan yang dikenali server",
            ($baris[0] ?? []) === $judulDari($kelas), json_encode($baris[0] ?? []));
        $cek("template {$nama} membawa baris contoh", count($baris) >= 2, (string) count($baris));
    }

    // ── 3. Supplier: import xlsx ─────────────────────────────────────────────────────────────
    echo "\n=== 3. Supplier — import berkas Excel ===\n";

    $judulSup = $judulDari(SupplierController::class);
    $h = $impor($supCtl, $xlsx([
        $judulSup,
        ['UJI-SUP-1', 'UJI CV Alpha', 'material', 'Budi', '031-1', '0811', '031-2', 'a@b.co', 'Jl. A', 'Surabaya', 'CV Alpha', '1234567890123456', 'BCA', '111222', 'CV Alpha', 'Aktif'],
        ['', 'UJI CV Beta tanpa kode', 'jasa', '', '', '', '', '', '', 'Sidoarjo', '', '', '', '', '', 'Aktif'],
        ['UJI-SUP-3', '', 'material', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['UJI-SUP-4', 'UJI NPWP pendek', '', '', '', '', '', '', '', '', '', '123', '', '', '', ''],
        ['UJI-SUP-5', 'UJI email ngawur', '', '', '', '', '', 'bukan-email', '', '', '', '', '', '', '', ''],
        ['UJI-SUP-1', 'UJI kode kembar', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ], 'sup1'));

    $r = $h['import'];
    $cek('dua baris sah masuk', ($r['baru'] ?? 0) === 2, json_encode($r));
    $cek('empat baris bermasalah dilewati', ($r['lewat'] ?? 0) === 4, json_encode($r['lewat'] ?? null));
    // Inti fiturnya: yang gagal DISEBUT, lengkap dengan nomor barisnya.
    $galat = implode(' | ', $r['galat'] ?? []);
    $cek('nama kosong dilaporkan beserta nomor baris', str_contains($galat, 'Baris 4') && str_contains($galat, 'Nama Supplier kosong'), $galat);
    // Panjang yang sah 15 atau 16 angka, dan 13-14 angka dipulihkan nol depannya — seluruh
    // aturannya diuji tersendiri di uji-import-npwp.php. Di sini cukup dipastikan barisnya
    // benar-benar dilaporkan lewat jalur import.
    $cek('NPWP yang panjangnya tidak sah dilaporkan', str_contains($galat, 'Baris 5') && str_contains($galat, 'NPWP'), $galat);
    $cek('email tidak valid dilaporkan', str_contains($galat, 'Baris 6') && str_contains($galat, 'Email'), $galat);
    $cek('kode kembar dalam satu berkas dilaporkan', str_contains($galat, 'Baris 7') && str_contains($galat, 'dua kali'), $galat);

    $s1 = BsSupplier::where('code', 'UJI-SUP-1')->first();
    $cek('supplier berkode tersimpan', $s1 !== null);
    $cek('seluruh kolomnya terisi', $s1 && $s1->npwp === '1234567890123456' && $s1->bank_name === 'BCA'
        && $s1->city === 'Surabaya' && $s1->phone2 === '0811', json_encode($s1?->only(['npwp', 'bank_name', 'city', 'phone2'])));

    $s2 = BsSupplier::where('name', 'UJI CV Beta tanpa kode')->first();
    $cek('yang tanpa kode dibuatkan kode', $s2 !== null && $s2->code !== '');
    // count()+1 akan menabrak kode terpakai dan bertabrakan saat dua petugas mengimpor
    // bersamaan; nextCode() meneruskan deret SP.##### yang sudah dipakai master.
    $cek('kodenya mengikuti deret SP.#####', $s2 !== null && str_starts_with((string) $s2->code, 'SP.'),
        (string) $s2?->code);

    $cek('catatan data belum lengkap disebut',
        collect($r['catatan'] ?? [])->contains(fn ($c) => str_contains($c, 'rekening')), json_encode($r['catatan'] ?? []));

    // ── 4. Berkas sebagian tidak menimpa kolom lain ──────────────────────────────────────────
    echo "\n=== 4. Berkas sebagian TIDAK menghapus data lain ===\n";

    $h = $impor($supCtl, $csv([
        ['Kode', 'Nama Supplier'],
        ['UJI-SUP-1', 'UJI CV Alpha (ejaan dibetulkan)'],
    ], 'sup2'));
    $s1b = BsSupplier::where('code', 'UJI-SUP-1')->first();
    $cek('namanya berubah', $s1b?->name === 'UJI CV Alpha (ejaan dibetulkan)', (string) $s1b?->name);
    $cek('NPWP-nya TIDAK ikut terhapus', $s1b?->npwp === '1234567890123456', json_encode($s1b?->npwp));
    $cek('rekeningnya TIDAK ikut terhapus', $s1b?->bank_account_no === '111222', json_encode($s1b?->bank_account_no));
    $cek('dihitung sebagai perbaruan, bukan baris baru',
        ($h['import']['ubah'] ?? 0) === 1 && ($h['import']['baru'] ?? 0) === 0, json_encode($h['import']));

    echo "\n=== 5. Mode \"lewati\" benar-benar melewati ===\n";

    $h = $impor($supCtl, $csv([
        ['Kode', 'Nama Supplier'],
        ['UJI-SUP-1', 'UJI JANGAN BERUBAH'],
    ], 'sup3'), 'lewati');
    $s1c = BsSupplier::where('code', 'UJI-SUP-1')->first();
    $cek('datanya tidak tersentuh', $s1c?->name === 'UJI CV Alpha (ejaan dibetulkan)', (string) $s1c?->name);
    $cek('dilaporkan sebagai dilewati', ($h['import']['lewat'] ?? 0) === 1, json_encode($h['import']));

    // ── 6. Judul kolom seadanya & CSV ala Excel Indonesia ────────────────────────────────────
    echo "\n=== 6. Judul kolom seadanya tetap terbaca ===\n";

    // Berkas dari lapangan hampir tidak pernah memakai judul persis seperti template.
    $h = $impor($supCtl, $csv([
        ['KODE SUPPLIER', 'NAMA VENDOR', 'No. Telp', 'KOTA'],
        ['UJI-SUP-9', 'UJI Judul Seadanya', '031-999', 'Gresik'],
    ], 'sup4', ';', true));
    $s9 = BsSupplier::where('code', 'UJI-SUP-9')->first();
    $cek('CSV ber-BOM & titik-koma terbaca', $s9 !== null, json_encode($h));
    $cek('"No. Telp" dikenali sebagai Telepon', $s9?->phone === '031-999', (string) $s9?->phone);
    $cek('"NAMA VENDOR" dikenali sebagai nama', $s9?->name === 'UJI Judul Seadanya', (string) $s9?->name);
    // 'KODE SUPPLIER' mengandung kata SUPPLIER; tanpa penjagaan ia ikut tertangkap sebagai
    // kolom Nama dan seluruh berkas terimpor dengan nama berisi kode.
    $cek('"KODE SUPPLIER" tidak tertangkap sebagai kolom Nama', $s9?->name !== 'UJI-SUP-9', (string) $s9?->name);

    // ── 7. Karyawan ──────────────────────────────────────────────────────────────────────────
    echo "\n=== 7. Karyawan — nominal, tanggal, dan master ===\n";

    $dept = DB::table('bs_hr_departments')->orderBy('id')->first();
    $judulEmp = $judulDari(EmployeeController::class);

    $h = $impor($empCtl, $xlsx([
        $judulEmp,
        // Gaji gaya Indonesia (titik ribuan) & tunjangan gaya Inggris (koma ribuan).
        ['UJI-EMP-1', 'UJI Andi', 'Staff', $dept?->code ?? '', '', '2026-01-15', '7.500.000', '1,200,000', 'BCA', '999', 'Aktif'],
        // Tanggal serial Excel.
        ['UJI-EMP-2', 'UJI Budi', 'Staff', '', '', '46037', '6500000', '', '', '', 'Nonaktif'],
        ['UJI-EMP-3', 'UJI Dept Ngawur', 'Staff', 'TIDAK-ADA-DEPT', '', '', '5000000', '', '', '', ''],
        ['UJI-EMP-4', 'UJI Gaji Ngawur', 'Staff', '', '', '', 'seratus juta', '', '', '', ''],
        ['UJI-EMP-5', '', 'Staff', '', '', '', '5000000', '', '', '', ''],
    ], 'emp1'));

    $r = $h['import'];
    $cek('dua karyawan sah masuk', ($r['baru'] ?? 0) === 2, json_encode($r));
    $cek('tiga baris bermasalah dilewati', ($r['lewat'] ?? 0) === 3, json_encode($r['lewat'] ?? null));

    $galat = implode(' | ', $r['galat'] ?? []);
    // Departemen yang tidak dikenal DITOLAK, bukan dibiarkan kosong: karyawan tanpa
    // departemen menghilang dari laporan payroll per departemen tanpa satu pun tanda.
    $cek('departemen tak dikenal DITOLAK, bukan dikosongkan',
        str_contains($galat, 'Baris 4') && str_contains($galat, 'Departemen'), $galat);
    $cek('gaji tak terbaca dilaporkan', str_contains($galat, 'Baris 5') && str_contains($galat, 'Gaji Pokok'), $galat);
    $cek('nama kosong dilaporkan', str_contains($galat, 'Baris 6'), $galat);

    $e1 = BsEmployee::where('nik', 'UJI-EMP-1')->first();
    $cek('"7.500.000" terbaca 7,5 juta', (float) ($e1?->base_salary ?? 0) === 7500000.0, (string) $e1?->base_salary);
    $cek('"1,200,000" terbaca 1,2 juta', (float) ($e1?->allowance ?? 0) === 1200000.0, (string) $e1?->allowance);
    $cek('tanggal masuk terbaca', optional($e1?->join_date)->toDateString() === '2026-01-15', (string) optional($e1?->join_date)->toDateString());
    $cek('departemen tertaut', $dept === null || (int) $e1?->department_id === (int) $dept->id, (string) $e1?->department_id);

    $e2 = BsEmployee::where('nik', 'UJI-EMP-2')->first();
    $cek('serial tanggal Excel terbaca', optional($e2?->join_date)->toDateString() === '2026-01-15',
        (string) optional($e2?->join_date)->toDateString());
    $cek('status Nonaktif terbaca', $e2 !== null && (bool) $e2->is_active === false, json_encode($e2?->is_active));

    echo "\n=== 8. Status kosong TIDAK berarti nonaktif ===\n";

    // Mayoritas berkas tidak mengisi kolom status. Membacanya "nonaktif" akan mengimpor
    // seluruh karyawan dalam keadaan mati, dan itu baru ketahuan saat payroll berikutnya kosong.
    $h = $impor($empCtl, $csv([
        ['NIK', 'Nama Karyawan', 'Gaji Pokok'],
        ['UJI-EMP-7', 'UJI Tanpa Kolom Status', '5000000'],
    ], 'emp2'));
    $e7 = BsEmployee::where('nik', 'UJI-EMP-7')->first();
    $cek('karyawan tanpa kolom status tetap AKTIF', $e7 !== null && (bool) $e7->is_active === true,
        json_encode($e7?->is_active));

    echo "\n=== 9. Berkas rusak ditolak dengan pesan yang bisa dibaca ===\n";

    $h = $impor($supCtl, $csv([['Kolom Ngawur', 'Kolom Lain'], ['a', 'b']], 'sup5'));
    $cek('berkas tanpa kolom nama ditolak',
        str_contains((string) $h['error'], 'Nama Supplier'), (string) $h['error']);

    $p = $tmp.'/bukan.pdf';
    file_put_contents($p, 'bukan spreadsheet');
    $sampah[] = $p;
    $h = $impor($supCtl, new UploadedFile($p, 'bukan.pdf', null, null, true));
    $cek('format berkas asing ditolak', str_contains((string) $h['error'], '.xlsx'), (string) $h['error']);

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    foreach ($sampah as $p) {
        @unlink($p);
    }
    $sisa = BsSupplier::withTrashed()->where('code', 'like', 'UJI-SUP-%')->count()
        + BsSupplier::withTrashed()->where('name', 'like', 'UJI %')->count()
        + BsEmployee::withTrashed()->where('nik', 'like', 'UJI-EMP-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
