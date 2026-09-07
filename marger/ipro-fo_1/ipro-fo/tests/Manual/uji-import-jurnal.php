<?php

declare(strict_types=1);

/*
 * Uji Import Jurnal massal — unduh template, isi, unggah, jadi banyak jurnal.
 *
 * ALUR YANG DIJAGA adalah yang paling wajar dilakukan orang pertama kali: unduh templatenya,
 * unggah balik apa adanya untuk melihat cara kerjanya. Bila kode akun contohnya tidak ada di
 * Bagan Akun tenant ini, langkah itu berakhir dengan "akun tidak ditemukan" — dan fiturnya
 * terkesan rusak padahal hanya contohnya yang tidak cocok. Karena itu isi templatenya diambil
 * dari akun yang benar-benar ada.
 *
 * DIJAGA JUGA:
 *   · Satu berkas melahirkan BANYAK jurnal — dikelompokkan per REFERENSI, bukan satu jurnal
 *     gelondongan. Inilah arti "import massal dari banyak jurnal".
 *   · Jurnal yang tidak balance DITOLAK, dan tidak ada satu pun jurnal dari berkas itu yang
 *     terlanjur tersimpan.
 *   · Dry-run tidak menyimpan apa pun.
 *   · Sumber 'import' terdaftar di BsJournal::SOURCES. Saringan Sumber pada Detail Transaksi
 *     Jurnal dibangun dari daftar itu; tanpa terdaftar, jurnal hasil impor tidak bisa
 *     disaring sama sekali — justru sesudah mengimpor ratusan baris orang paling
 *     membutuhkannya.
 *   · Pratinjau format di layar berasal dari sumber yang sama dengan berkas templatenya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-import-jurnal.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalImportController;
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

$user = \App\Models\User::query()->find((int) DB::table('users')->orderBy('id')->value('id'));
auth()->setUser($user);
$ctrl = new JournalImportController();
$journal = app(JournalService::class);

/** Tulis baris ke .xlsx lewat penulis yang dipakai controllernya, lalu bungkus jadi unggahan. */
$berkas = function (array $rows) use ($ctrl): UploadedFile {
    $tulis = new ReflectionMethod($ctrl, 'xlsxDownload');
    $tulis->setAccessible(true);
    // Path ABSOLUT — UploadedFile menganggap berkas ber-path kosong tidak sah, dan
    // validasi 'required' akan menolaknya seolah tak ada berkas yang diunggah.
    $p = sys_get_temp_dir().'/uji-jurnal-'.bin2hex(random_bytes(4)).'.xlsx';
    file_put_contents($p, $tulis->invoke($ctrl, $rows, 'x.xlsx')->getContent());

    return new UploadedFile($p, 'Import-Jurnal.xlsx', null, null, true);
};
$unggah = function (UploadedFile $f, bool $dryRun = false) use ($ctrl, $journal, $user) {
    // Sesi DIBERSIHKAN dulu: pesan flash dari unggahan sebelumnya masih tersimpan di sesi yang
    // sama, dan tanpa ini sebuah unggahan yang gagal masih terbaca "success" milik unggahan
    // sebelumnya — pemeriksaan lulus atas pesan yang bukan miliknya.
    session()->flush();
    $req = Request::create('/x', 'POST', $dryRun ? ['dry_run' => 1] : []);
    $req->files->set('file', $f);
    $req->setUserResolver(fn () => $user);
    $res = $ctrl->store($req, $journal);
    $s = $res->getSession();

    return ['error' => $s->get('error'), 'success' => $s->get('success')];
};

DB::beginTransaction();

try {
    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Sumber 'import' terdaftar & bisa disaring ===\n";

    $cek("BsJournal::SOURCES memuat 'import'", array_key_exists('import', BsJournal::SOURCES),
        implode(', ', array_keys(BsJournal::SOURCES)));
    $cek('labelnya terbaca manusia, bukan kode mentah',
        (BsJournal::SOURCES['import'] ?? '') === 'Import Jurnal', BsJournal::SOURCES['import'] ?? '(tidak ada)');

    echo "\n=== 2. Template memakai akun yang benar-benar ada ===\n";

    $contoh = new ReflectionMethod($ctrl, 'contohBaris');
    $contoh->setAccessible(true);
    $baris = $contoh->invoke($ctrl);

    $cek('header sesuai format importnya',
        $baris[0] === ['TANGGAL', 'KODE_AKUN', 'DEBIT', 'KREDIT', 'KETERANGAN', 'REFERENSI'],
        json_encode($baris[0]));
    $kode = array_map(fn ($r) => (string) $r[1], array_slice($baris, 1));
    $adaSemua = BsAccount::whereIn('code', $kode)->count() === count(array_unique($kode));
    $cek('semua kode akun contohnya ada di Bagan Akun', $adaSemua, implode(', ', $kode));

    // Dua jurnal contoh, masing-masing balance — kalau tidak, unggah-balik pasti gagal.
    $per = [];
    foreach (array_slice($baris, 1) as $r) {
        $per[(string) $r[5]]['d'] = ($per[(string) $r[5]]['d'] ?? 0) + (float) $r[2];
        $per[(string) $r[5]]['k'] = ($per[(string) $r[5]]['k'] ?? 0) + (float) $r[3];
    }
    $cek('contohnya berisi 2 jurnal', count($per) === 2, implode(', ', array_keys($per)));
    foreach ($per as $ref => $v) {
        $cek("contoh {$ref} balance", abs($v['d'] - $v['k']) < 0.01, "D={$v['d']} K={$v['k']}");
    }

    echo "\n=== 3. Berkas templatenya terbaca pembaca umum repo ini ===\n";

    // Penulis & pembacanya dua kode berbeda; kalau tidak sepakat, orang mengunduh berkas
    // yang tidak bisa dibuka Excel-nya sendiri.
    $unduh = new ReflectionMethod($ctrl, 'xlsxDownload');
    $unduh->setAccessible(true);
    $p = sys_get_temp_dir().'/uji-tpl-'.bin2hex(random_bytes(4)).'.xlsx';
    file_put_contents($p, $unduh->invoke($ctrl, $baris, 'x.xlsx')->getContent());
    $balik = SimpleXlsx::rows($p);
    @unlink($p);
    $cek('jumlah barisnya sama', count($balik) === count($baris), count($balik).' vs '.count($baris));
    $cek('header terbaca utuh', array_map('strval', $balik[0] ?? []) === $baris[0], json_encode($balik[0] ?? []));

    echo "\n=== 4. Unduh template → unggah balik → jadi 2 jurnal ===\n";

    $sebelum = BsJournal::count();
    $r = $unggah($berkas($baris));
    $cek('tidak ada galat', $r['error'] === null, (string) $r['error']);
    $cek('2 jurnal terbentuk dari satu berkas', BsJournal::count() - $sebelum === 2,
        'bertambah '.(BsJournal::count() - $sebelum));

    $baru = BsJournal::orderByDesc('id')->limit(2)->get();
    $cek('keduanya bersumber import', $baru->every(fn ($j) => $j->source === 'import'),
        $baru->pluck('source')->implode(', '));
    $cek('referensinya terbawa jadi pembeda jurnal',
        $baru->pluck('reference')->sort()->values()->all() === ['JU-001', 'JU-002'],
        $baru->pluck('reference')->implode(', '));

    echo "\n=== 5. Banyak jurnal dalam satu berkas, bukan satu gelondongan ===\n";

    // Inilah inti "import massal dari banyak jurnal": REFERENSI yang memisahkannya.
    $banyak = [$baris[0]];
    for ($i = 1; $i <= 5; $i++) {
        $banyak[] = [$baris[1][0], $baris[1][1], 100000 * $i, 0, 'Uji massal '.$i, 'UJI-M-'.$i];
        $banyak[] = [$baris[2][0], $baris[2][1], 0, 100000 * $i, 'Uji massal '.$i, 'UJI-M-'.$i];
    }
    $sebelum = BsJournal::count();
    $r = $unggah($berkas($banyak));
    $cek('5 referensi → 5 jurnal', BsJournal::count() - $sebelum === 5,
        'bertambah '.(BsJournal::count() - $sebelum).' — '.(string) $r['error']);

    $lima = BsJournal::where('source', 'import')->orderByDesc('id')->limit(5)->get();
    $cek('tiap jurnal berisi barisnya sendiri (2 baris)',
        $lima->every(fn ($j) => DB::table('bs_journal_lines')->where('journal_id', $j->id)->count() === 2));

    echo "\n=== 6. Berkas yang tidak balance ditolak seluruhnya ===\n";

    // Menyimpan sebagian dari berkas yang cacat jauh lebih buruk daripada menolaknya:
    // separuhnya masuk buku besar tanpa ada yang tahu bagian mana.
    $pincang = [
        $baris[0],
        [$baris[1][0], $baris[1][1], 999000, 0, 'Pincang', 'UJI-PINCANG'],
        [$baris[2][0], $baris[2][1], 0, 111000, 'Pincang', 'UJI-PINCANG'],
    ];
    $sebelum = BsJournal::count();
    $r = $unggah($berkas($pincang));
    $cek('ditolak dengan pesan', $r['error'] !== null && $r['success'] === null, (string) $r['error']);
    $cek('pesannya menyebut REFERENSI jurnalnya', str_contains((string) $r['error'], 'UJI-PINCANG'), (string) $r['error']);
    $cek('tidak ada jurnal yang terlanjur tersimpan', BsJournal::count() === $sebelum,
        'bertambah '.(BsJournal::count() - $sebelum));

    // INI pemeriksaan yang sebenarnya menguji sifat "semua atau tidak sama sekali": jurnal
    // pertama SAH, yang kedua pincang. Tanpa transaksi, jurnal pertama sudah terlanjur masuk
    // buku besar — lalu orang memperbaiki berkasnya dan mengunggah ulang seluruhnya, dan
    // jurnal pertama itu masuk untuk kedua kalinya tanpa satu pun galat.
    $separuh = [
        $baris[0],
        [$baris[1][0], $baris[1][1], 250000, 0, 'Sah', 'UJI-SAH'],
        [$baris[2][0], $baris[2][1], 0, 250000, 'Sah', 'UJI-SAH'],
        [$baris[1][0], $baris[1][1], 999000, 0, 'Pincang', 'UJI-PINCANG-2'],
        [$baris[2][0], $baris[2][1], 0, 111000, 'Pincang', 'UJI-PINCANG-2'],
    ];
    $sebelum = BsJournal::count();
    $r = $unggah($berkas($separuh));
    $cek('berkas yang gagal DI TENGAH juga ditolak', $r['error'] !== null, (string) $r['error']);
    $cek('jurnal yang sah sebelumnya ikut dibatalkan — tidak ada separuh berkas yang masuk',
        BsJournal::count() === $sebelum, 'bertambah '.(BsJournal::count() - $sebelum));
    $cek('jurnal UJI-SAH benar-benar tidak tersimpan',
        BsJournal::where('reference', 'UJI-SAH')->count() === 0);

    echo "\n=== 7. Akun tak dikenal ditolak, disebut barisnya ===\n";

    $akunAsing = [
        $baris[0],
        [$baris[1][0], '99999999999', 100000, 0, 'Akun asing', 'UJI-ASING'],
        [$baris[2][0], $baris[2][1], 0, 100000, 'Akun asing', 'UJI-ASING'],
    ];
    $sebelum = BsJournal::count();
    $r = $unggah($berkas($akunAsing));
    $cek('ditolak', $r['error'] !== null, (string) $r['error']);
    $cek('pesannya menyebut nomor barisnya', str_contains((string) $r['error'], 'Baris 2'), (string) $r['error']);
    $cek('tidak ada yang tersimpan', BsJournal::count() === $sebelum);

    echo "\n=== 8. Dry-run tidak menyimpan apa pun ===\n";

    $sebelum = BsJournal::count();
    $r = $unggah($berkas($baris), dryRun: true);
    $cek('dry-run melaporkan jumlah jurnalnya', str_contains((string) $r['success'], '2 jurnal'), (string) $r['success']);
    $cek('tidak ada jurnal tersimpan saat dry-run', BsJournal::count() === $sebelum,
        'bertambah '.(BsJournal::count() - $sebelum));

    echo "\n=== 9. Pratinjau di layar = isi berkas templatenya ===\n";

    // Dua salinan contoh yang ditulis terpisah lambat laun berbeda, dan yang dilihat orang
    // di layar bukan yang benar-benar terunduh.
    $req = Request::create('/f', 'GET');
    $req->setUserResolver(fn () => $user);
    // Header X-Inertia membuat Inertia memulangkan JSON berisi props-nya, bukan HTML.
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $isi = json_decode((string) $ctrl->form($req)->toResponse($req)->getContent(), true) ?: [];
    $tplLayar = (string) ($isi['props']['template'] ?? '');
    $mau = collect($baris)->map(fn ($r) => implode(',', array_map('strval', $r)))->implode("\n");
    $cek('pratinjaunya sama dengan isi templatenya', $tplLayar === $mau,
        "\n        layar   : ".str_replace("\n", ' / ', $tplLayar)."\n        template: ".str_replace("\n", ' / ', $mau));

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
