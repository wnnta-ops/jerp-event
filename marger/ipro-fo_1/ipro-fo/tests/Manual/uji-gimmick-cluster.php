<?php

declare(strict_types=1);

/**
 * Uji: Cluster pada Kategori Gimmick (BO).
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-gimmick-cluster.php
 *
 * Yang diperiksa:
 *   1. Skema — kolom cluster_id ada, dan keunikan kode kini per (tenant, cluster, kode).
 *   2. Satu kode boleh dipakai ulang di cluster berbeda, tetapi tidak dua kali di cluster sama.
 *   3. Pencarian kategori saat Posting BO: kategori clusternya menang, kategori tanpa cluster
 *      jadi cadangan, dan cluster tanpa kategori apa pun tetap dapat cadangannya.
 *   4. Akun yang terpakai benar-benar akun milik cluster itu (bukan akun cluster lain).
 *   5. Cakupan Rincian Biaya dihitung per cluster — satu kategori tidak membuat kolom terlihat
 *      aman padahal cluster lain belum terpegang.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Domain\Foundation\Tenant\Models\Cluster;
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

echo "\n=== 1. Skema ===\n";

$cek('bs_gimmick_categories punya kolom cluster_id',
    Schema::hasColumn('bs_gimmick_categories', 'cluster_id'));
$cek('bs_ap_refund_queue punya kolom cluster_id',
    Schema::hasColumn('bs_ap_refund_queue', 'cluster_id'));

$indeks = collect(DB::select('SHOW INDEX FROM bs_gimmick_categories'))
    ->groupBy('Key_name')->map(fn ($g) => $g->pluck('Column_name')->all());
$cek('keunikan kode kini per (tenant, cluster, kode)',
    ($indeks['bs_gimmick_categories_tenant_cluster_code_unique'] ?? null) === ['tenant_id', 'cluster_id', 'code'],
    json_encode($indeks['bs_gimmick_categories_tenant_cluster_code_unique'] ?? null));
$cek('keunikan lama (tenant, kode) sudah dilepas',
    ! isset($indeks['bs_gimmick_categories_tenant_id_code_unique']),
    implode(',', array_keys($indeks->all())));

// Dua akun debit berbeda, supaya bisa dibuktikan akun cluster yang benar yang terpakai.
$akun = BsAccount::where('is_postable', true)->where('is_active', true)->orderBy('code')->limit(3)->get();
$cek('ada tiga akun postable untuk diuji', $akun->count() === 3, (string) $akun->count());
[$akunA, $akunB, $akunKredit] = [$akun[0], $akun[1], $akun[2]];

$poster = new class
{
    /**
     * Salinan aturan pencarian kategori di IproApPoster::gimmickCategory() — kategori
     * clusternya menang, kategori tanpa cluster jadi cadangan.
     */
    public function cari(string $nama, ?int $clusterId): ?BsGimmickCategory
    {
        $dasar = fn () => BsGimmickCategory::where('is_active', 1)->where('name', $nama);

        if ($clusterId) {
            $milik = $dasar()->where('cluster_id', $clusterId)->first();
            if ($milik) {
                return $milik;
            }
        }

        return $dasar()->whereNull('cluster_id')->first();
    }
};

DB::beginTransaction();
try {
    // Cluster uji dibuat SENDIRI, tidak memungut dua cluster pertama yang kebetulan ada.
    // Sejak cluster menjadi cerminan master Lokasi FO, isinya tergantung berapa Lokasi yang
    // dipunyai FO — di mesin yang baru hanya satu, dan uji ini akan berhenti sebelum menguji
    // apa pun. Keduanya ikut di-rollback bersama transaksi ini.
    $projectId = (int) (Cluster::withTrashed()->value('project_id') ?: 1);
    $buatCluster = fn (string $kode) => Cluster::create([
        'project_id' => $projectId, 'code' => $kode, 'name' => 'Cluster Uji '.$kode,
    ]);
    $cA = $buatCluster('UJI-A');
    $cB = $buatCluster('UJI-B');
    // Cluster ketiga SENGAJA dibiarkan tanpa kategori — dialah yang membuktikan perilaku
    // cadangan. Sebelumnya ia dipungut lewat indeks ketiga dari daftar cluster, yang berarti
    // ia bisa saja mengenai cluster yang justru sudah punya kategori dan pemeriksaannya
    // menjadi bohong.
    $cC = $buatCluster('UJI-C');
    $clusters = Cluster::orderBy('code')->get();

    $cek('dua cluster uji terbentuk', $cA->exists && $cB->exists && $cA->id !== $cB->id);
    $cek('keduanya terbaca di daftar cluster', $clusters->whereIn('id', [$cA->id, $cB->id])->count() === 2,
        (string) $clusters->count());

    // Kolom 'kpr' di bagan nyata sudah dipegang kategori tanpa cluster. Kategori itu adalah
    // cadangan yang sah, jadi selama ia ada tidak akan pernah ada cluster yang dianggap
    // kosong — dan bagian 5 tidak akan menguji apa pun. Tautannya dilepas dulu di dalam
    // transaksi ini (ikut di-rollback) supaya keadaannya benar-benar bersih.
    BsGimmickCategory::where('kpr_biaya_key', 'kpr')->update(['kpr_biaya_key' => null, 'sync_kpr' => false]);

    $nama = 'UJI BIAYA KPR CLUSTER';
    $buat = function (?int $clusterId, int $debitId) use ($nama, $akunKredit) {
        $c = BsGimmickCategory::create([
            'cluster_id' => $clusterId,
            'code' => 'UJI-KPR'.($clusterId ?? 'X'),
            'name' => $nama,
            'kpr_biaya_key' => 'kpr',
            'sync_kpr' => true,
            'debit_account_id' => $debitId,
            'payable_account_id' => $akunKredit->id,
            'is_active' => true,
        ]);
        $c->accounts()->create(['side' => 'debit', 'account_id' => $debitId, 'portion' => 100, 'sort_order' => 0]);
        $c->accounts()->create(['side' => 'credit', 'account_id' => $akunKredit->id, 'portion' => 100, 'sort_order' => 0]);

        return $c;
    };

    echo "\n=== 2. Satu kode dipakai ulang antar cluster ===\n";

    $katA = $buat($cA->id, (int) $akunA->id);
    $katB = $buat($cB->id, (int) $akunB->id);
    $cek('kategori bernama sama boleh ada di dua cluster',
        $katA->exists && $katB->exists && $katA->id !== $katB->id);
    $cek('keduanya memakai akun debit yang berbeda',
        (int) $katA->debit_account_id !== (int) $katB->debit_account_id);

    $kembar = null;
    try {
        // Kode yang sama di cluster yang sama harus ditolak database.
        BsGimmickCategory::create([
            'cluster_id' => $cA->id, 'code' => 'UJI-KPR'.$cA->id, 'name' => $nama.' KEMBAR',
            'debit_account_id' => $akunA->id, 'payable_account_id' => $akunKredit->id, 'is_active' => true,
        ]);
    } catch (\Throwable $e) {
        $kembar = $e;
    }
    $cek('kode kembar di cluster yang sama tetap ditolak', $kembar !== null,
        $kembar ? '' : 'tersimpan padahal seharusnya ditolak');

    echo "\n=== 3. Pencarian kategori saat Posting BO ===\n";

    $cek('cluster A dapat kategori cluster A',
        $poster->cari($nama, (int) $cA->id)?->id === $katA->id);
    $cek('cluster B dapat kategori cluster B',
        $poster->cari($nama, (int) $cB->id)?->id === $katB->id);

    $cek('cluster tanpa kategori sendiri belum dapat apa-apa (belum ada cadangan)',
        $poster->cari($nama, (int) $cC->id) === null);

    // Cadangan: kategori tanpa cluster, berlaku untuk semua cluster.
    $katUmum = $buat(null, (int) $akunKredit->id);
    $cek('kategori clusternya tetap menang walau cadangan sudah ada',
        $poster->cari($nama, (int) $cA->id)?->id === $katA->id);
    $cek('cluster tanpa kategori sendiri jatuh ke cadangan',
        $poster->cari($nama, (int) $cC->id)?->id === $katUmum->id);
    $cek('GMC tanpa cluster memakai cadangan',
        $poster->cari($nama, null)?->id === $katUmum->id);

    echo "\n=== 4. Akun yang terpakai benar milik clusternya ===\n";

    $akunDari = function (?int $clusterId) use ($poster, $nama): ?int {
        $c = $poster->cari($nama, $clusterId);

        return $c?->accounts->firstWhere('side', 'debit')?->account_id;
    };
    $cek('akun debit cluster A = akun A', $akunDari((int) $cA->id) === (int) $akunA->id,
        (string) $akunDari((int) $cA->id));
    $cek('akun debit cluster B = akun B', $akunDari((int) $cB->id) === (int) $akunB->id,
        (string) $akunDari((int) $cB->id));
    $cek('akun kedua cluster memang berbeda',
        $akunDari((int) $cA->id) !== $akunDari((int) $cB->id));

    echo "\n=== 5. Cakupan Rincian Biaya per cluster ===\n";

    // Ulangi perhitungan cakupan controller untuk kolom 'kpr'.
    $cakupan = function (string $key) use ($clusters) {
        $isi = BsGimmickCategory::whereNotNull('kpr_biaya_key')->where('kpr_biaya_key', $key)
            ->get(['code', 'cluster_id', 'sync_kpr']);
        $terpegang = $isi->pluck('cluster_id')->filter()->unique();
        $adaCadangan = $isi->whereNull('cluster_id')->isNotEmpty();

        return [
            'kosong' => $adaCadangan || $isi->isEmpty()
                ? []
                : $clusters->whereNotIn('id', $terpegang->all())->pluck('code')->values()->all(),
            'isi' => $clusters->whereIn('id', $terpegang->all())->pluck('code')->values()->all(),
        ];
    };

    // Dengan cadangan hidup, tidak ada cluster yang dianggap kosong.
    $c1 = $cakupan('kpr');
    $cek('cadangan menutup seluruh cluster', $c1['kosong'] === [], implode(',', $c1['kosong']));

    // Cadangan dihapus: cluster yang belum punya kategori harus muncul sebagai kosong.
    $katUmum->accounts()->delete();
    $katUmum->delete();
    $c2 = $cakupan('kpr');
    $kosongSeharusnya = $clusters->whereNotIn('id', [$cA->id, $cB->id])->pluck('code')->values()->all();
    $cek('tanpa cadangan, cluster yang terlewat muncul sebagai kosong',
        $c2['kosong'] === $kosongSeharusnya,
        'dapat '.json_encode($c2['kosong']).' harusnya '.json_encode($kosongSeharusnya));
    $cek('cluster yang sudah punya kategori tidak ikut diperingatkan',
        ! in_array($cA->code, $c2['kosong'], true) && ! in_array($cB->code, $c2['kosong'], true));
    $cek('cluster yang sudah terpegang terdaftar',
        $c2['isi'] === collect([$cA->code, $cB->code])->sort()->values()->all()
        || $c2['isi'] === [$cA->code, $cB->code],
        json_encode($c2['isi']));

    echo "\n=== 5b. Lokasi dihapus di FO tidak mengunci kategorinya ===\n";

    // Urutan yang benar-benar terjadi: kategori dibuat untuk sebuah cluster, lalu Lokasinya
    // dihapus di FO sehingga clusternya ikut ditandai terhapus. Sesudah itu operator membuka
    // kategori tersebut untuk mengganti NAMANYA saja. Bila validasi cluster_id menolak cluster
    // yang sudah dipensiunkan tanpa kecuali, kategori itu terkunci total — nama, akun, porsi,
    // semuanya ikut gagal karena kolom yang tidak ia sentuh.
    $cA->delete();

    $ctl = app(App\Http\Controllers\Web\BusinessSupport\Payable\GimmickCategoryController::class);
    $pengguna = App\Models\User::query()->get()
        ->first(fn ($u) => $u->can('businesssupport.setup.manage'));
    $cek('ada pengguna berwenang untuk menguji simpan', $pengguna !== null);

    if ($pengguna) {
        $muatan = [
            'cluster_id' => $katA->cluster_id, 'code' => $katA->code,
            'name' => $katA->name.' (DIUBAH)', 'kpr_biaya_key' => $katA->kpr_biaya_key,
            'sync_kpr' => true, 'is_active' => true,
            'debits'  => [['account_id' => $akunA->id, 'portion' => 100]],
            'credits' => [['account_id' => $akunKredit->id, 'portion' => 100]],
        ];
        $req = Illuminate\Http\Request::create('/x', 'POST', $muatan);
        $req->setUserResolver(fn () => $pengguna);

        $pesan = '';
        try {
            $ctl->update($req, $katA);
        } catch (Illuminate\Validation\ValidationException $e) {
            $pesan = json_encode($e->errors());
        }
        $cek('kategori dengan cluster nonaktif masih bisa disimpan ulang', $pesan === '', $pesan);
        $cek('perubahan namanya benar-benar tersimpan',
            $katA->fresh()->name === $muatan['name'], (string) $katA->fresh()->name);
        $cek('clusternya tidak diam-diam berubah jadi "semua cluster"',
            (int) $katA->fresh()->cluster_id === (int) $cA->id);

        // Cluster nonaktif tetap DITOLAK sebagai pilihan BARU — kelonggarannya hanya untuk
        // cluster yang memang sudah tersimpan pada kategori itu.
        $req2 = Illuminate\Http\Request::create('/x', 'POST', ['cluster_id' => $cA->id] + $muatan + ['code' => 'UJI-BARU']);
        $req2->setUserResolver(fn () => $pengguna);
        $ditolak = false;
        try {
            $ctl->store($req2);
        } catch (Illuminate\Validation\ValidationException $e) {
            $ditolak = array_key_exists('cluster_id', $e->errors());
        }
        $cek('kategori BARU tetap dilarang memilih cluster nonaktif', $ditolak);
    }
} finally {
    DB::rollBack();
}

echo "\n=== 6. Rollback bersih ===\n";
$cek('kategori uji tidak tertinggal',
    BsGimmickCategory::where('name', 'like', 'UJI BIAYA KPR CLUSTER%')->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
