<?php

declare(strict_types=1);

/**
 * Uji: master Lokasi FO → pilihan Cluster di Back Office.
 *
 * Cluster di BO bukan master tersendiri — ia cerminan Setup Unit → Lokasi di FO, yang di
 * seluruh kode FO memang disebut "cluster" dan tampil pada Master Unit. Uji ini membuktikan
 * cerminan itu benar-benar mengikuti, bukan sekadar terisi sekali lalu membatu.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-lokasi-cluster-sinkron.php
 *
 * Bagian A dibungkus transaksi FO lalu di-rollback. Baris yang lahir di ipro_bo TIDAK ikut
 * ter-rollback (beda database) sehingga dihapus sendiri di blok finally.
 * Bagian B sengaja DI LUAR transaksi — jalur peristiwa (Lokasi::saved/deleted/restored)
 * memakai afterCommit, jadi di dalam transaksi yang di-rollback ia tidak akan pernah jalan.
 */

use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$bo = DB::connection((string) config('ipro.connection', 'ipro_bo'));
$sync = app(IproMasterSync::class);
$tenant = (int) config('ipro.tenant_id', 1);

/** Baris cluster BO untuk sebuah lokasi FO, apa pun status hapusnya. */
$cluster = fn (int $lokasiId) => $bo->table('clusters')
    ->where('tenant_id', $tenant)->where('fo_lokasi_id', $lokasiId)->first();

$jumlahCluster = fn (int $lokasiId) => (int) $bo->table('clusters')
    ->where('tenant_id', $tenant)->where('fo_lokasi_id', $lokasiId)->count();

$projectId = (int) (DB::table('fo_lokasi')->value('project_id') ?: 1);
$lahir = [];   // id cluster ipro_bo yang dibuat uji ini — dibersihkan manual

echo "\n=== 0. Prasyarat ===\n";
$cek('BO sudah punya kolom clusters.fo_lokasi_id',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasColumn('clusters', 'fo_lokasi_id'));

// ─────────────────────────────────────────────────────────────────────────────
// BAGIAN A — semantik syncLokasi() itu sendiri
// ─────────────────────────────────────────────────────────────────────────────

DB::beginTransaction();
try {
    $lokasiId = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId,
        'kode' => 'ZUJ1', 'nama' => 'LOKASI UJI SATU', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $baris = fn () => DB::table('fo_lokasi')->where('id', $lokasiId)->first();

    echo "\n=== 1. Sekali sinkron, satu cluster, label persis seperti di FO ===\n";

    $id1 = $sync->syncLokasi($baris());
    $lahir[] = $id1;
    $c = $cluster($lokasiId);

    $cek('terbentuk tepat satu cluster', $jumlahCluster($lokasiId) === 1, (string) $jumlahCluster($lokasiId));
    // Inilah inti permintaannya: kode yang tampil di BO = kode yang tampil di Master Unit FO,
    // bukan kode sintetis 'FOL-{id}' yang tidak pernah ada di layar FO mana pun.
    $cek('code cluster = kode Lokasi di FO', $c && $c->code === 'ZUJ1', (string) ($c->code ?? '-'));
    $cek('name cluster = nama Lokasi di FO', $c && $c->name === 'LOKASI UJI SATU', (string) ($c->name ?? '-'));
    $cek('cluster tertaut ke id Lokasi', $c && (int) $c->fo_lokasi_id === $lokasiId);
    $cek('cluster tidak lahir dalam keadaan terhapus', $c && $c->deleted_at === null);

    echo "\n=== 2. Idempoten — diulang tidak menggandakan ===\n";

    for ($i = 0; $i < 5; $i++) {
        $sync->syncLokasi($baris());
    }
    $cek('lima kali sinkron tetap satu baris', $jumlahCluster($lokasiId) === 1, (string) $jumlahCluster($lokasiId));
    $cek('id cluster tidak berubah', (int) $cluster($lokasiId)->id === $id1);

    echo "\n=== 3. Ganti nama & kode di FO benar-benar menyusul ===\n";

    // Inilah yang gagal sebelum perubahan ini: jalur lama pulang lebih awal begitu barisnya
    // ketemu, jadi perbaikan nama di FO tidak pernah sampai ke BO.
    DB::table('fo_lokasi')->where('id', $lokasiId)->update(['nama' => 'LOKASI UJI BERUBAH']);
    $sync->syncLokasi($baris());
    $cek('nama menyusul ke BO', $cluster($lokasiId)->name === 'LOKASI UJI BERUBAH',
        $cluster($lokasiId)->name);

    DB::table('fo_lokasi')->where('id', $lokasiId)->update(['kode' => 'ZUJ9']);
    $sync->syncLokasi($baris());
    $c = $cluster($lokasiId);
    $cek('kode menyusul ke BO', $c->code === 'ZUJ9', $c->code);
    // Kalau kode dipakai sebagai kunci, mengganti kode akan melahirkan cluster kedua dan
    // seluruh kategori gimmick yang menunjuk yang lama menggantung tanpa pemberitahuan.
    $cek('id cluster TETAP walau kodenya berganti', (int) $c->id === $id1);
    $cek('tidak lahir cluster kedua', $jumlahCluster($lokasiId) === 1, (string) $jumlahCluster($lokasiId));

    echo "\n=== 4. Hapus & pulihkan di FO ikut tercermin ===\n";

    DB::table('fo_lokasi')->where('id', $lokasiId)->update(['deleted_at' => now()]);
    $sync->syncLokasi($baris());
    $cek('Lokasi terhapus → cluster ikut ditandai terhapus', $cluster($lokasiId)->deleted_at !== null);

    DB::table('fo_lokasi')->where('id', $lokasiId)->update(['deleted_at' => null]);
    $sync->syncLokasi($baris());
    $c = $cluster($lokasiId);
    // Pencarian yang menyaring deleted_at akan menabrak indeks unik di sini, bukan menghidupkan
    // kembali barisnya — jadi ini sekaligus pembuktian bahwa penyaringan itu tidak dilakukan.
    $cek('Lokasi dipulihkan → cluster hidup lagi tanpa tabrakan', $c->deleted_at === null);
    $cek('id cluster tetap sama sesudah dipulihkan', (int) $c->id === $id1);
    $cek('tetap satu baris', $jumlahCluster($lokasiId) === 1, (string) $jumlahCluster($lokasiId));

    echo "\n=== 5. Baris warisan 'FOL-{id}' diadopsi, bukan dikembari ===\n";

    $lokasi2 = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId,
        'kode' => 'ZUJ2', 'nama' => 'LOKASI UJI DUA', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $warisanId = (int) $bo->table('clusters')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'fo_lokasi_id' => null,
        'code' => 'FOL-'.$lokasi2, 'name' => 'Cluster '.$lokasi2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lahir[] = $warisanId;

    $hasil = $sync->syncLokasi(DB::table('fo_lokasi')->where('id', $lokasi2)->first());
    $c2 = $cluster($lokasi2);
    $cek('baris warisan yang diadopsi, bukan baris baru', $hasil === $warisanId,
        $hasil.' vs '.$warisanId);
    $cek('kode warisan diganti kode Lokasi sesungguhnya', $c2->code === 'ZUJ2', $c2->code);
    $cek('nama karangan diganti nama Lokasi', $c2->name === 'LOKASI UJI DUA', $c2->name);
    $cek('tidak ada cluster kembar untuk lokasi itu', $jumlahCluster($lokasi2) === 1,
        (string) $jumlahCluster($lokasi2));

    echo "\n=== 6. Lokasi cacat ditolak, bukan dikarang ===\n";

    // Versi sebelumnya mengarang "Cluster 7" saat nama kosong — persis kekeliruan yang sedang
    // diperbaiki: BO menampilkan cluster yang tidak pernah ada di FO.
    $tertolak = false;
    try {
        $sync->syncLokasi((object) ['id' => 999001, 'kode' => '', 'nama' => '', 'deleted_at' => null]);
    } catch (\Throwable $e) {
        $tertolak = true;
    }
    $cek('Lokasi tanpa kode/nama ditolak', $tertolak);
    $cek('tidak ada cluster karangan yang tertinggal', $jumlahCluster(999001) === 0);

    echo "\n=== 7. Sinkron unit tidak ikut mati karena cluster bermasalah ===\n";

    // cluster adalah atribut pelengkap unit; menggagalkan seluruh sinkron unit karena
    // Lokasinya bermasalah akan menahan harga & status di antrean.
    $unitPalsu = (object) [
        'id' => 999002, 'kode_unit' => 'ZUJI-TES-001', 'lokasi_id' => 999003,
        'status' => 'TERSEDIA', 'harga_jual' => 0, 'unit_type_id' => null,
    ];
    $unitId = null;
    $lolos = true;
    try {
        $unitId = $sync->syncUnit($unitPalsu);
    } catch (\Throwable $e) {
        $lolos = false;
    }
    $cek('unit dengan lokasi tak dikenal tetap tersimpan', $lolos && $unitId !== null);
    if ($unitId) {
        $u = $bo->table('units')->where('id', $unitId)->first();
        $cek('cluster_id-nya kosong, bukan menunjuk cluster karangan', $u && $u->cluster_id === null);
        $bo->table('units')->where('id', $unitId)->delete();
    }

    echo "\n=== 7b. Cluster yang sudah benar tidak tertimpa NULL saat resolusi gagal ===\n";

    // Inilah kerusakan paling senyap: satu kedipan koneksi ke tabel clusters, lalu setiap unit
    // yang tersentuh sesudahnya kehilangan clusternya — sementara job melapor sukses, jadi
    // tidak ada retry maupun catatan kegagalan yang memberi tahu.
    $lokUnit = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId,
        'kode' => 'ZUJ4', 'nama' => 'LOKASI UJI UNIT', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitBaik = (object) [
        'id' => 999010, 'kode_unit' => 'ZUJI-TES-002', 'lokasi_id' => $lokUnit,
        'status' => 'TERSEDIA', 'harga_jual' => 0, 'unit_type_id' => null,
    ];
    $idUnit = $sync->syncUnit($unitBaik);
    $lahir[] = (int) ($cluster($lokUnit)->id ?? 0);
    $sebelum = (int) ($bo->table('units')->where('id', $idUnit)->value('cluster_id') ?: 0);
    $cek('unit pertama dapat clusternya', $sebelum > 0, (string) $sebelum);

    // Sinkron ulang unit yang SAMA, kali ini lokasinya tak bisa dipecahkan.
    $unitBaik->lokasi_id = 999011;
    $sync->syncUnit($unitBaik);
    $sesudah = (int) ($bo->table('units')->where('id', $idUnit)->value('cluster_id') ?: 0);
    $cek('cluster_id lama dipertahankan, bukan dikosongkan', $sesudah === $sebelum,
        $sebelum.' → '.$sesudah);

    // Unit yang memang tidak punya lokasi tetap boleh dikosongkan — pembedaan itu yang penting.
    $unitBaik->lokasi_id = null;
    $sync->syncUnit($unitBaik);
    $cek('unit yang memang tanpa lokasi tetap dikosongkan',
        $bo->table('units')->where('id', $idUnit)->value('cluster_id') === null);
    $bo->table('units')->where('id', $idUnit)->delete();

    echo "\n=== 7c. Kegagalan tidak di-memo (worker berumur panjang) ===\n";

    // Servisnya singleton dan worker Horizon hidup berjam-jam; memo yang menyimpan kegagalan
    // sesaat akan dipakai ulang sepanjang itu tanpa pernah mencoba lagi.
    $unitBaik->lokasi_id = $lokUnit;
    $idUnit2 = $sync->syncUnit($unitBaik);
    $cek('sesudah gagal sekali, sinkron berikutnya tetap menemukan clusternya',
        (int) $bo->table('units')->where('id', $idUnit2)->value('cluster_id') === $sebelum);
    $bo->table('units')->where('id', $idUnit2)->delete();

    echo "\n=== 7d. Cluster pensiunan TIDAK dihidupkan oleh Lokasi berkode sama ===\n";

    // Cluster contoh yang sengaja dipensiunkan membawa serta unit-unit lama yang masih
    // menunjuknya; menghidupkannya kembali hanya karena sebuah Lokasi baru kebetulan berkode
    // sama akan membatalkan keputusan migrasi yang memensiunkannya.
    $pensiunId = (int) $bo->table('clusters')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'fo_lokasi_id' => null,
        'code' => 'ZUJ5', 'name' => 'CLUSTER PENSIUN', 'deleted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lahir[] = $pensiunId;
    $lokPensiun = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId,
        'kode' => 'ZUJ5', 'nama' => 'LOKASI BARU BERKODE SAMA', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $idBaru = $sync->syncLokasi(DB::table('fo_lokasi')->where('id', $lokPensiun)->first());
    $lahir[] = $idBaru;
    $pensiun = $bo->table('clusters')->where('id', $pensiunId)->first();
    $cek('cluster pensiunan tidak diadopsi', $idBaru !== $pensiunId, $idBaru.' vs '.$pensiunId);
    $cek('cluster baru dipakaikan kode disambiguasi, bukan gagal terbit',
        $cluster($lokPensiun)?->code === 'ZUJ5#'.$lokPensiun, (string) ($cluster($lokPensiun)->code ?? '-'));
    $cek('cluster pensiunan tetap tertandai terhapus', $pensiun->deleted_at !== null);
    $cek('cluster pensiunan tidak ikut berpemilik', $pensiun->fo_lokasi_id === null);

    echo "\n=== 7e. Kode yang bertukar akhirnya konvergen, tidak terkunci salah ===\n";

    // Dua Lokasi yang kodenya ditukar sementara BO tertinggal beberapa sinkron. Sebelum
    // perbaikan, keduanya saling menabrak indeks unik SELAMANYA — backfill per jam pun tidak
    // pernah menyembuhkannya, dan dropdown menampilkan kode yang salah dengan meyakinkan.
    $lokX = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode' => 'ZUX1', 'nama' => 'LOKASI X',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $lokY = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode' => 'ZUX2', 'nama' => 'LOKASI Y',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $baca = fn (int $id) => DB::table('fo_lokasi')->where('id', $id)->first();
    $idX = $sync->syncLokasi($baca($lokX));
    $idY = $sync->syncLokasi($baca($lokY));
    $lahir[] = $idX;
    $lahir[] = $idY;

    // Ditukar di FO. FO sendiri melarang tukar langsung (kode unik per project), jadi operator
    // menempuh kode sementara — persis seperti di lapangan. Selama ketiga langkah ini BO
    // tertinggal (antrean mati / sinkron sempat dimatikan), lalu menyusul lewat backfill.
    DB::table('fo_lokasi')->where('id', $lokX)->update(['kode' => 'ZUX0']);
    DB::table('fo_lokasi')->where('id', $lokY)->update(['kode' => 'ZUX1']);
    DB::table('fo_lokasi')->where('id', $lokX)->update(['kode' => 'ZUX2']);

    $galat = null;
    try {
        for ($putaran = 0; $putaran < 3; $putaran++) {
            $sync->syncLokasi($baca($lokX));
            $sync->syncLokasi($baca($lokY));
        }
    } catch (\Throwable $e) {
        $galat = $e->getMessage();
    }
    $cek('backfill berulang tidak melempar', $galat === null, (string) $galat);
    $cek('Lokasi X akhirnya berkode ZUX2', $cluster($lokX)->code === 'ZUX2', $cluster($lokX)->code);
    $cek('Lokasi Y akhirnya berkode ZUX1', $cluster($lokY)->code === 'ZUX1', $cluster($lokY)->code);
    $cek('id cluster keduanya tidak bergeser',
        (int) $cluster($lokX)->id === $idX && (int) $cluster($lokY)->id === $idY);
    $cek('tidak ada cluster kembar yang lahir dari pertukaran itu',
        $jumlahCluster($lokX) === 1 && $jumlahCluster($lokY) === 1);

    echo "\n=== 7f. Galat integritas selain kunci kembar tidak ditelan ===\n";

    // SQLSTATE 23000 mencakup pelanggaran FK juga. Menelannya akan mengganti galat yang
    // menyebut tabel & nilainya dengan tuduhan konkurensi yang menyesatkan.
    // Harus Lokasi yang BELUM punya cluster: identitasnya (tenant, fo_lokasi_id) tidak memuat
    // project, jadi Lokasi yang clusternya sudah ada akan berhenti di langkah 1 dan tidak
    // pernah sampai ke INSERT yang melanggar FK.
    $lokFk = (int) DB::table('fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode' => 'ZUX3', 'nama' => 'LOKASI FK',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config()->set('ipro.project_id', 987654);
    $syncCacat = new IproMasterSync;
    $pesan = '';
    try {
        $syncCacat->syncLokasi($baca($lokFk));
    } catch (\Throwable $e) {
        $pesan = $e->getMessage();
    }
    config()->set('ipro.project_id', $projectId);
    $cek('project_id yang tidak ada memunculkan galat FK apa adanya',
        str_contains($pesan, 'foreign key') || str_contains($pesan, '1452'),
        mb_substr($pesan, 0, 120));
    $cek('bukan disamarkan jadi "gagal setelah tiga percobaan"',
        ! str_contains($pesan, 'tiga percobaan'), mb_substr($pesan, 0, 120));
} finally {
    DB::rollBack();
    // ipro_bo beda database — tidak ikut ter-rollback, jadi dibereskan sendiri.
    foreach (array_filter($lahir) as $id) {
        $bo->table('clusters')->where('id', $id)->delete();
    }
    $bo->table('clusters')->whereIn('code', ['ZUJ1', 'ZUJ2', 'ZUJ3', 'ZUJ4', 'ZUJ5', 'ZUJ9', 'ZUX0', 'ZUX1', 'ZUX2', 'ZUX3'])->delete();
}

// ─────────────────────────────────────────────────────────────────────────────
// BAGIAN B — jalur peristiwa: menyimpan Lokasi di FO benar-benar sampai ke BO
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== 8. Menyimpan Lokasi di FO langsung muncul di BO ===\n";

$lok = null;
try {
    $lok = Lokasi::create([
        'tenant_id' => $tenant, 'project_id' => $projectId,
        'kode' => 'ZUJ3', 'nama' => 'LOKASI UJI PERISTIWA', 'is_active' => true,
    ]);

    $c = $cluster((int) $lok->id);
    $cek('cluster terbentuk tanpa perlu satu unit pun', $c !== null);
    // Selama cluster menumpang sinkron unit, Lokasi baru tidak akan pernah muncul di BO
    // sampai ada unit pertama yang diunggah ke sana — padahal kategori gimmicknya justru
    // perlu disiapkan lebih dulu.
    $cek('kodenya sama dengan yang diketik operator di FO', $c && $c->code === 'ZUJ3',
        (string) ($c->code ?? '-'));

    $lok->update(['nama' => 'LOKASI UJI PERISTIWA (DIUBAH)']);
    $cek('ganti nama di FO menyusul ke BO',
        $cluster((int) $lok->id)?->name === 'LOKASI UJI PERISTIWA (DIUBAH)',
        (string) ($cluster((int) $lok->id)->name ?? '-'));

    $lok->delete();
    $cek('hapus di FO menandai cluster terhapus di BO',
        $cluster((int) $lok->id)?->deleted_at !== null);

    $lok->restore();
    $cek('pulihkan di FO menghidupkan lagi clusternya',
        $cluster((int) $lok->id)?->deleted_at === null);
} finally {
    if ($lok) {
        $bo->table('clusters')->where('fo_lokasi_id', $lok->id)->delete();
        DB::table('fo_lokasi')->where('id', $lok->id)->delete();
    }
}

echo "\n=== 9. Keadaan akhir bersih ===\n";
$cek('tidak ada cluster uji yang tertinggal',
    (int) $bo->table('clusters')->whereIn('code', ['ZUJ1', 'ZUJ2', 'ZUJ3', 'ZUJ4', 'ZUJ5', 'ZUJ9', 'ZUX0', 'ZUX1', 'ZUX2', 'ZUX3'])->count() === 0);
$cek('tidak ada Lokasi uji yang tertinggal',
    (int) DB::table('fo_lokasi')->whereIn('kode', ['ZUJ1','ZUJ2','ZUJ3','ZUJ4','ZUJ5','ZUJ9','ZUX0','ZUX1','ZUX2','ZUX3'])->count() === 0);

echo "\n=== 10. Hasil nyata yang dilihat operator ===\n";
$hidup = $bo->table('clusters')->whereNull('deleted_at')->orderBy('code')->get(['code', 'name', 'fo_lokasi_id']);
$foKode = DB::table('fo_lokasi')->whereNull('deleted_at')->orderBy('kode')->pluck('kode')->all();
$cek('setiap cluster hidup di BO berasal dari FO',
    $hidup->every(fn ($c) => $c->fo_lokasi_id !== null),
    $hidup->pluck('code')->implode(', '));
$cek('daftar cluster BO sama persis dengan daftar Lokasi FO',
    $hidup->pluck('code')->sort()->values()->all() === collect($foKode)->sort()->values()->all(),
    'BO['.$hidup->pluck('code')->implode(',').'] vs FO['.implode(',', $foKode).']');
foreach ($hidup as $c) {
    echo "        pilihan: {$c->code} — {$c->name}\n";
}

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
