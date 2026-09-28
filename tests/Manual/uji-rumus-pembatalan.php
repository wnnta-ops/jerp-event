<?php

/**
 * Cocokkan HitungPembatalan dengan lembar rumus yang diberikan pemilik proses.
 *
 * ── BERBEDA DARI SKRIP LAIN DI FOLDER INI ──
 *
 * Yang lain berjalan pada database MySQL hasil `migrate` dan membungkus tulisannya dalam
 * transaksi. Skrip ini TIDAK: ia MEMBUAT SENDIRI dua tabel yang dibutuhkannya di database
 * SQLite sekali pakai, sebab rantai migrasi repo ini memuat ALTER TABLE ... MODIFY yang
 * hanya dipahami MySQL sehingga `migrate` penuh di SQLite berhenti di tengah. Yang diuji
 * cuma rumusnya, dan rumusnya hanya menyentuh `settings` dan `fo_kontrak_tagihan`.
 *
 * Konsekuensinya skrip ini MENGHAPUS kedua tabel itu sebelum membuatnya ulang. Pada database
 * sungguhan itu berarti seluruh setelan dan seluruh jadwal tagihan lenyap — karena itu ada
 * penjaga di bawah yang menolak jalan pada koneksi selain SQLite. Jangan dicopot.
 *
 * Menjalankannya (tidak perlu MySQL, tidak perlu migrate):
 *
 *   MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD://var/www/html" -w //var/www/html \
 *     jerp/app:latest sh -c 'touch /tmp/u.sqlite && php //var/www/html/tests/Manual/uji-rumus-pembatalan.php'
 */

// Impor DI ATAS kode, bukan di bawahnya. `use` diproses berurutan saat kompilasi, jadi
// alias yang dideklarasikan sesudah pemakaiannya TIDAK berlaku surut: Kernel::class pada
// baris bootstrap akan menjadi string 'Kernel' begitu saja, dan container melemparkan
// "Target class [Kernel] does not exist".
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\HitungPembatalan;
use App\Settings\FoSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Config di image ini SUDAH DI-CACHE, sehingga variabel lingkungan tidak lagi terbaca —
// koneksinya karena itu disetel lewat config(), bukan lewat -e pada docker run.
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => '/tmp/u.sqlite',
    'cache.default' => 'array',
    'settings.cache.enabled' => false,
]);
DB::purge();

// PENJAGA — jangan dicopot. Skrip ini menghapus lalu membuat ulang `settings` dan
// `fo_kontrak_tagihan`; dijalankan pada MySQL kerja, itu membuang seluruh setelan aplikasi
// dan seluruh jadwal tagihan. Penyetelan config di atas seharusnya sudah cukup, tetapi
// config yang ter-cache pernah mengalahkan hal yang lebih kuat dari itu.
if (DB::connection()->getDriverName() !== 'sqlite') {
    fwrite(STDERR, 'BERHENTI: koneksi bukan SQLite ('.DB::connection()->getDriverName()."). Skrip ini membuang tabel — jangan dijalankan pada database sungguhan.\n");
    exit(2);
}

Schema::dropIfExists('settings');
Schema::dropIfExists('fo_kontrak_tagihan');

Schema::create('settings', function ($t) {
    $t->id();
    $t->string('group');
    $t->string('name');
    $t->boolean('locked')->default(false);
    $t->json('payload');
    $t->timestamps();
});

Schema::create('fo_kontrak_tagihan', function ($t) {
    $t->id();
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('kontrak_id');
    $t->string('tipe', 8);
    $t->string('nama_tagihan')->nullable();
    $t->integer('urutan')->default(0);
    $t->decimal('pelunasan', 18, 2)->default(0);
});

// Seluruh properti FoSettings diisi lewat refleksi: yang diuji hanya dua di antaranya,
// tetapi spatie menolak memuat kelasnya bila ADA SATU saja properti yang tak punya baris.
$bawaan = [
    'blt_batal_sepihak_nilai' => 1_500_000.0,
    'blt_batal_sepihak_pph_persen' => 2.5,
];
foreach ((new ReflectionClass(FoSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
    $nama = $prop->getName();
    $tipe = $prop->getType()?->getName();
    $nilai = $bawaan[$nama] ?? match ($tipe) {
        'bool' => false,
        'int' => 0,
        'float' => 0.0,
        default => $prop->getType()?->allowsNull() ? null : '',
    };
    DB::table('settings')->insert([
        'group' => 'fo', 'name' => $nama, 'locked' => 0,
        'payload' => json_encode($nilai), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

$svc = app(HitungPembatalan::class);
$rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
$lulus = 0;
$gagal = 0;

$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal, $rp) {
    $ok = abs((float) $dapat - (float) $harap) < 0.005;
    $ok ? $lulus++ : $gagal++;
    printf("  %-4s %-46s %18s   (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label, $rp($dapat), $rp($harap));
};

/** Pasang ulang tagihan sebuah kontrak, lalu kembalikan modelnya. */
$kontrak = function (int $id, float $utj, float $dp, float $tdp = 0, float $adm = 0) {
    DB::table('fo_kontrak_tagihan')->where('kontrak_id', $id)->delete();
    $baris = [];
    if ($utj > 0) {
        $baris[] = ['kontrak_id' => $id, 'tipe' => 'BF', 'nama_tagihan' => 'BOOKING FEE 1', 'urutan' => 1, 'pelunasan' => $utj];
    }
    if ($dp > 0) {
        $baris[] = ['kontrak_id' => $id, 'tipe' => 'DP', 'nama_tagihan' => 'DP', 'urutan' => 2, 'pelunasan' => $dp];
    }
    if ($tdp > 0) {
        $baris[] = ['kontrak_id' => $id, 'tipe' => 'TDP', 'nama_tagihan' => 'TAMBAH DP', 'urutan' => 3, 'pelunasan' => $tdp];
    }
    if ($adm > 0) {
        $baris[] = ['kontrak_id' => $id, 'tipe' => 'ADM', 'nama_tagihan' => 'UANG MASUK', 'urutan' => 4, 'pelunasan' => $adm];
    }
    if ($baris) {
        DB::table('fo_kontrak_tagihan')->insert($baris);
    }
    $k = new Kontrak;
    $k->id = $id;

    return $k;
};

echo "\n=== REFUND (berlaku untuk KOL 2-5 dan reject bank) ===\n";
$k = $kontrak(1, 3_000_000, 0);
foreach (['REJECT_BANK', 'KOL_2', 'KOL_3', 'KOL_4', 'KOL_5', 'TUTUP_BUKU'] as $alasan) {
    $h = $svc->pengembalian($k, $alasan);
    $cek($alasan.' → pengembalian UTJ', $h['refund'], 3_000_000);
}

echo "\n=== BATAL SEPIHAK (user memutuskan mundur) ===\n";
$k = $kontrak(2, 3_000_000, 0);
$h = $svc->pengembalian($k, 'BATAL_SEPIHAK', 0);
$cek('UTJ dibayar (hangus)', $h['utj'], 3_000_000);
$cek('pengembalian customer', $h['refund'], 0);

echo "\n=== BATAL SEPIHAK (mundur, telah membayar DP) — administrasi 10% ===\n";
$k = $kontrak(3, 3_000_000, 6_000_000);
$h = $svc->pengembalian($k, 'BATAL_SEPIHAK', 10);
$cek('UTJ dibayar (hangus)', $h['utj'], 3_000_000);
$cek('DP yang telah dibayar', $h['dp'], 6_000_000);
$cek('administrasi 10%', $h['potongan'], 600_000);
$cek('PENGEMBALIAN DP CUSTOMER', $h['refund'], 5_400_000);

echo "\n=== BATAL SEPIHAK (customer tidak jadi akad) — administrasi 30% ===\n";
$k = $kontrak(4, 3_000_000, 150_000_000);
$h = $svc->pengembalian($k, 'BATAL_SEPIHAK', 30);
$cek('DP yang telah dibayar', $h['dp'], 150_000_000);
$cek('administrasi 30%', $h['potongan'], 45_000_000);
$cek('PENGEMBALIAN DP CUSTOMER', $h['refund'], 105_000_000);

echo "\n=== BLT BATAL SEPIHAK ===\n";
$b = $svc->bltBatalSepihak();
$cek('BLT', $b['nilai'], 1_500_000);
$cek('PPh 21 (A x 2,5%)', $b['pph'], 37_500);
$cek('BLT NETT (A-B)', $b['nett'], 1_462_500);

echo "\n=== TAMBAH DP ikut dasar potongan (kontrak nyata di server test) ===\n";
// Kontraknya persis seperti yang tampil di layar: uang mukanya SELURUHNYA masuk sebagai TDP,
// tanpa satu pun baris bertipe DP. Menghitung DP saja membuat baris ini terbaca nol sementara
// pembeli sudah menyetor ratusan juta — dan tidak ada galat apa pun yang menandainya.
$k = $kontrak(8, 3_000_000, 0, 100_000_000, 6_880_000);
$h = $svc->pengembalian($k, 'BATAL_SEPIHAK', 10);
$cek('uang muka = DP 0 + Tambah DP 100 jt', $h['dp'], 100_000_000);
$cek('administrasi 10%', $h['potongan'], 10_000_000);
$cek('pengembalian', $h['refund'], 90_000_000);
$cek('UTJ tetap terbaca (ADM tidak mencemari)', $h['utj'], 3_000_000);

// DP dan Tambah DP berdampingan -> DIJUMLAHKAN, bukan salah satu yang menang.
$k = $kontrak(9, 3_000_000, 6_000_000, 4_000_000);
$h = $svc->pengembalian($k, 'BATAL_SEPIHAK', 10);
$cek('DP 6 jt + Tambah DP 4 jt', $h['dp'], 10_000_000);
$cek('administrasi 10%', $h['potongan'], 1_000_000);
$cek('pengembalian', $h['refund'], 9_000_000);

// Keluarga UTJ tidak ikut berubah: Tambah DP sebanyak apa pun tidak menambah pengembalian.
$cek('Reject Bank tetap UTJ saja', $svc->pengembalian($k, 'REJECT_BANK')['refund'], 3_000_000);

echo "\n=== Penjaga tepi ===\n";
$k = $kontrak(5, 3_000_000, 6_000_000);
$cek('persen 0 → DP kembali utuh', $svc->pengembalian($k, 'BATAL_SEPIHAK', 0)['refund'], 6_000_000);
$cek('persen 100 → tidak negatif', $svc->pengembalian($k, 'BATAL_SEPIHAK', 100)['refund'], 0);
$cek('persen 150 → dijepit di 100', $svc->pengembalian($k, 'BATAL_SEPIHAK', 150)['refund'], 0);
$cek('persen -5 → dijepit di 0', $svc->pengembalian($k, 'BATAL_SEPIHAK', -5)['refund'], 6_000_000);
$k = $kontrak(6, 0, 0);
$cek('belum bayar apa pun, Reject Bank', $svc->pengembalian($k, 'REJECT_BANK')['refund'], 0);

$h = $svc->pengembalian($kontrak(7, 3_000_000, 6_000_000), 'LAINNYA', 0);
$manual = $h['refund'] === null ? 'null' : 'BUKAN null';
$manual === 'null' ? $lulus++ : $gagal++;
printf("  %-4s %-46s %18s   (harap null — diisi operator)\n", $manual === 'null' ? 'OK' : 'GAGAL', 'LAINNYA → sistem tidak berpendapat', $manual);

printf("\n==================================================\n  LULUS %d · GAGAL %d\n==================================================\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
