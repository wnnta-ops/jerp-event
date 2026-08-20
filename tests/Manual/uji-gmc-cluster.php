<?php

/**
 * Uji: Cluster pada GMC (FO) dan penerusannya ke antrean Posting Invoice BO.
 *
 * Jalankan pada DATABASE UJI, bukan database kerja:
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-gmc-cluster.php
 *
 * Yang diperiksa:
 *   1. Skema — fo_gmc.lokasi_id ada.
 *   2. GMC menyimpan clusternya, dan GMC lama tanpa cluster jatuh ke unit pada SPR-nya.
 *   3. Posting BO meneruskan cluster itu ke antrean BO, dan kategori yang terpakai adalah
 *      kategori milik cluster tersebut — bukan kategori cluster lain.
 *   4. Cluster FO → cluster BO dipetakan lewat kode FOL-{id}, kesepakatan yang sama dengan
 *      IproMasterSync.
 *
 * Tulisan di FO dibungkus transaksi lalu di-rollback; baris antrean yang lahir di BO
 * dihapus di akhir (beda database, tidak ikut rollback).
 */

use App\Modules\FO\FinanceAR\Models\Gmc;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

$bo = DB::connection(config('ipro.connection', 'ipro_bo'));
$tenantId = (int) config('ipro.tenant_id', 1);
$noGmcUji = [];

echo "\n=== 1. Skema ===\n";
$cek('fo_gmc punya kolom lokasi_id', Schema::hasColumn('fo_gmc', 'lokasi_id'));
$cek('fo_gmc_items ada', Schema::hasTable('fo_gmc_items'));

echo "\n=== 4. Pemetaan cluster FO → BO ===\n";

// Kesepakatan kode dibaca dari IproMasterSync, bukan diketik ulang di sini: kalau
// konvensinya berubah dan hanya salah satu tempat yang ikut, uji ini yang menangkapnya.
$sumberSync = file_get_contents(app_path('Modules/BO/IproBridge/Services/IproMasterSync.php'));
$cek('IproMasterSync membuat cluster berkode FOL-{id}',
    str_contains($sumberSync, "'FOL-' . \$lokasiId") || str_contains($sumberSync, "'FOL-'.\$lokasiId"),
    'konvensi kode cluster tidak ditemukan di IproMasterSync');

$sumberPoster = file_get_contents(app_path('Modules/BO/IproBridge/Services/IproApPoster.php'));
$cek('IproApPoster mencari cluster dengan kode yang sama',
    str_contains($sumberPoster, "'FOL-'.\$foLokasiId"),
    'IproApPoster memakai konvensi kode yang berbeda');

DB::beginTransaction();
try {
    echo "\n=== 2. GMC menyimpan clusternya ===\n";

    // Data pendukung seperlunya — database uji ini kosong, jadi rantai project → lokasi →
    // unit → kontrak dibangun sendiri agar ujinya tidak bergantung pada seeder mana pun.
    $companyId = DB::table('companies')->insertGetId([
        'tenant_id' => 1, 'code' => 'UJI', 'name' => 'UJI COMPANY', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $projectId = DB::table('projects')->insertGetId([
        'tenant_id' => 1, 'company_id' => $companyId, 'code' => 'UJIP', 'name' => 'UJI PROJECT',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $customerId = DB::table('fo_customers')->insertGetId([
        'tenant_id' => 1, 'full_name' => 'UJI CUSTOMER', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lokA = Lokasi::create(['tenant_id' => 1, 'project_id' => $projectId, 'kode' => 'UJIA', 'nama' => 'UJI CLUSTER A', 'is_active' => true]);
    $lokB = Lokasi::create(['tenant_id' => 1, 'project_id' => $projectId, 'kode' => 'UJIB', 'nama' => 'UJI CLUSTER B', 'is_active' => true]);

    $unitA = Unit::create(['tenant_id' => 1, 'project_id' => $projectId, 'lokasi_id' => $lokA->id,
        'blok' => 'UA', 'no_unit' => '01']);
    $kontrakA = Kontrak::create(['tenant_id' => 1, 'no_kontrak' => 'UJI-SPR-A', 'unit_id' => $unitA->id,
        'customer_id' => $customerId, 'tgl_kontrak' => now()->toDateString()]);

    $gmcPunyaCluster = Gmc::create([
        'tgl' => now(), 'kontrak_id' => $kontrakA->id, 'lokasi_id' => $lokB->id,
        'nama' => 'UJI GIMMICK CLUSTER', 'nilai' => 1_000_000, 'status' => 'DRAFT',
    ]);
    $cek('cluster yang dipilih tersimpan apa adanya',
        (int) $gmcPunyaCluster->fresh()->lokasi_id === (int) $lokB->id);

    $gmcTanpaCluster = Gmc::create([
        'tgl' => now(), 'kontrak_id' => $kontrakA->id,
        'nama' => 'UJI GIMMICK CLUSTER', 'nilai' => 1_000_000, 'status' => 'DRAFT',
    ]);
    $cek('GMC tanpa cluster memang tersimpan kosong',
        $gmcTanpaCluster->fresh()->lokasi_id === null);
    // Inilah yang menyelamatkan GMC lama: clusternya ditelusuri dari unit pada SPR-nya.
    $cek('GMC tanpa cluster jatuh ke cluster unit pada SPR-nya',
        $gmcTanpaCluster->lokasiDariKontrak() === (int) $lokA->id,
        (string) $gmcTanpaCluster->lokasiDariKontrak());

    $gmcTanpaKontrak = Gmc::create([
        'tgl' => now(), 'nama' => 'UJI GIMMICK CLUSTER', 'nilai' => 1_000_000, 'status' => 'DRAFT',
    ]);
    $cek('GMC tanpa SPR tidak memaksakan cluster apa pun',
        $gmcTanpaKontrak->lokasiDariKontrak() === null);

    echo "\n=== 3. Posting BO meneruskan clusternya ===\n";

    // Cluster BO tiruan untuk kedua lokasi uji, memakai konvensi FOL-{id}.
    $now = now();
    foreach ([$lokA, $lokB] as $l) {
        $bo->table('clusters')->insert([
            'tenant_id' => $tenantId, 'project_id' => (int) config('ipro.project_id', 1),
            'code' => 'FOL-'.$l->id, 'name' => $l->nama, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $clusterBoA = (int) $bo->table('clusters')->where('code', 'FOL-'.$lokA->id)->value('id');
    $clusterBoB = (int) $bo->table('clusters')->where('code', 'FOL-'.$lokB->id)->value('id');
    $cek('cluster BO tiruan terbentuk untuk kedua lokasi', $clusterBoA > 0 && $clusterBoB > 0);

    // Kategori Gimmick per cluster, dengan akun debit berbeda.
    $akun = $bo->table('bs_accounts')->where('is_postable', 1)->orderBy('code')->limit(3)->get(['id']);
    $cek('ada tiga akun postable di BO', $akun->count() === 3, (string) $akun->count());
    $namaKat = 'UJI GIMMICK CLUSTER';
    $buatKat = function (?int $clusterId, int $debitId, string $kode) use ($bo, $tenantId, $namaKat, $akun, $now) {
        $id = (int) $bo->table('bs_gimmick_categories')->insertGetId([
            'tenant_id' => $tenantId, 'cluster_id' => $clusterId, 'code' => $kode, 'name' => $namaKat,
            'kpr_biaya_key' => 'kpr', 'sync_kpr' => 1,
            'debit_account_id' => $debitId, 'payable_account_id' => (int) $akun[2]->id,
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $bo->table('bs_gimmick_category_accounts')->insert([
            ['gimmick_category_id' => $id, 'side' => 'debit', 'account_id' => $debitId, 'portion' => 100, 'sort_order' => 0],
            ['gimmick_category_id' => $id, 'side' => 'credit', 'account_id' => (int) $akun[2]->id, 'portion' => 100, 'sort_order' => 0],
        ]);

        return $id;
    };
    $katA = $buatKat($clusterBoA, (int) $akun[0]->id, 'UJI-CL-A');
    $katB = $buatKat($clusterBoB, (int) $akun[1]->id, 'UJI-CL-B');

    // Posting GMC yang clusternya dipilih sendiri (lokasi B).
    $noGmcUji[] = (string) $gmcPunyaCluster->no_gmc;
    \App\Filament\Fo\Resources\GmcResource::postGmcToBo($gmcPunyaCluster);
    $barisB = $bo->table('bs_ap_refund_queue')->where('kind', 'gmc')
        ->where('refund_no', $gmcPunyaCluster->no_gmc)->first();

    $cek('baris antrean terbentuk', $barisB !== null);
    $cek('cluster ikut tercatat di antrean', (int) ($barisB->cluster_id ?? 0) === $clusterBoB,
        'dapat '.($barisB->cluster_id ?? 'null').' harusnya '.$clusterBoB);
    $cek('kategori yang terpakai milik cluster B', (int) ($barisB->gimmick_category_id ?? 0) === $katB,
        'dapat '.($barisB->gimmick_category_id ?? 'null').' harusnya '.$katB);
    $cek('akun debit antrean = akun kategori cluster B',
        (int) ($barisB->offset_account_id ?? 0) === (int) $akun[1]->id,
        'dapat '.($barisB->offset_account_id ?? 'null').' harusnya '.$akun[1]->id);
    $cek('kolom Rincian Biaya KPR ikut terbawa', ($barisB->kpr_biaya_key ?? null) === 'kpr');

    // Posting GMC tanpa cluster — harus jatuh ke cluster unit pada SPR-nya (lokasi A).
    $noGmcUji[] = (string) $gmcTanpaCluster->no_gmc;
    \App\Filament\Fo\Resources\GmcResource::postGmcToBo($gmcTanpaCluster);
    $barisA = $bo->table('bs_ap_refund_queue')->where('kind', 'gmc')
        ->where('refund_no', $gmcTanpaCluster->no_gmc)->first();

    $cek('GMC tanpa cluster tetap mengenai cluster unit pada SPR-nya',
        (int) ($barisA->cluster_id ?? 0) === $clusterBoA,
        'dapat '.($barisA->cluster_id ?? 'null').' harusnya '.$clusterBoA);
    $cek('kategori yang terpakai milik cluster A', (int) ($barisA->gimmick_category_id ?? 0) === $katA,
        'dapat '.($barisA->gimmick_category_id ?? 'null').' harusnya '.$katA);
    $cek('akun debit kedua antrean memang berbeda',
        (int) $barisA->offset_account_id !== (int) $barisB->offset_account_id);

    $cek('status GMC berubah jadi DIPROSES',
        $gmcPunyaCluster->fresh()->status === 'DIPROSES' && $gmcTanpaCluster->fresh()->status === 'DIPROSES');

    // Bersihkan sisa di BO (beda database — tidak ikut rollback FO).
    $bo->table('bs_gimmick_category_accounts')->whereIn('gimmick_category_id', [$katA, $katB])->delete();
    $bo->table('bs_gimmick_categories')->whereIn('id', [$katA, $katB])->delete();
    $bo->table('bs_ap_refund_queue')->whereIn('refund_no', $noGmcUji)->delete();
    $bo->table('clusters')->whereIn('id', [$clusterBoA, $clusterBoB])->delete();
} finally {
    DB::rollBack();
}

echo "\n=== 5. Tidak ada sisa ===\n";
$cek('GMC uji tidak tertinggal di FO', Gmc::where('nama', 'UJI GIMMICK CLUSTER')->count() === 0);
$cek('antrean uji tidak tertinggal di BO',
    $bo->table('bs_ap_refund_queue')->whereIn('refund_no', $noGmcUji ?: ['-'])->count() === 0);
$cek('kategori uji tidak tertinggal di BO',
    $bo->table('bs_gimmick_categories')->where('name', 'UJI GIMMICK CLUSTER')->count() === 0);
$cek('cluster tiruan tidak tertinggal di BO',
    $bo->table('clusters')->where('code', 'like', 'FOL-%')->where('name', 'like', 'UJI CLUSTER%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
