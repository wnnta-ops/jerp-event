<?php

/**
 * Uji: kolom Supplier pada layar "Buat GMC Massal" (FO → Finance AR → GMC).
 *
 * Jalankan pada DATABASE UJI, bukan database kerja — lihat tests/Manual/README.md:
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-gmc-massal-supplier.php
 *
 * Yang diperiksa:
 *   1. Kolom Supplier ADA di skema form, WAJIB diisi (tanda bintang merahnya lahir dari
 *      ->required() yang sama dengan keempat kolom lain di layar itu).
 *   2. Daftar suppliernya benar-benar master Supplier Center BO (bs_suppliers @ ipro_bo) —
 *      dan SAMA PERSIS dengan yang dipakai Rincian Komponen pada form GMC tunggal. Dua
 *      daftar terpisah adalah cara paling mudah keduanya lambat laun menyaring hal yang
 *      berbeda tanpa ada yang menyadarinya.
 *   3. Supplier yang dipilih benar-benar TERSIMPAN, dan tersimpan pada tempat yang dibaca
 *      jalur Posting BO — kalau tidak, layarnya tetap berkata "N GMC dibuat" sementara
 *      hutangnya lahir tanpa supplier, tanpa satu pun galat.
 *   4. Baris tanpa supplier tidak menghasilkan GMC.
 *   5. Opsi supplier di-MEMOIZE — halaman ini memang dibangun dengan aturan "tak ada query
 *      per baris" (lihat docblock GmcMassal); daftar 762 supplier yang di-query ulang tiap
 *      render membuat tambah/hapus baris nyangkut.
 *
 * Tulisan FO dibungkus transaksi lalu di-rollback. Tidak ada tulisan ke BO di uji ini.
 */

use App\Filament\Fo\Resources\GmcResource\Pages\GmcMassal;
use App\Modules\FO\FinanceAR\Models\Gmc;
use App\Modules\FO\FinanceAR\Models\GmcItem;
use App\Modules\FO\MarketingSales\Models\Kontrak;
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

/** Telusuri skema form Filament sampai ketemu komponen bernama tertentu. */
$cariKomponen = function (array $komponen, string $nama) use (&$cariKomponen) {
    foreach ($komponen as $k) {
        if (method_exists($k, 'getName') && $k->getName() === $nama) {
            return $k;
        }
        foreach (['getChildComponents', 'getDefaultChildComponents'] as $m) {
            if (method_exists($k, $m)) {
                try {
                    $anak = $k->{$m}();
                } catch (\Throwable) {
                    continue;
                }
                if (is_array($anak) && ($t = $cariKomponen($anak, $nama)) !== null) {
                    return $t;
                }
            }
        }
    }

    return null;
};

echo "\n=== 0. Lingkungan uji ===\n";

$cek('database uji, bukan database kerja', DB::connection()->getDatabaseName() !== 'jerp',
    DB::connection()->getDatabaseName());
$cek('tabel fo_gmc & fo_gmc_items ada',
    Schema::hasTable('fo_gmc') && Schema::hasTable('fo_gmc_items'));
$cek('fo_gmc_items punya kolom supplier_id', Schema::hasColumn('fo_gmc_items', 'supplier_id'));

echo "\n=== 1. Kolom Supplier ada di form & WAJIB diisi ===\n";

$page = new GmcMassal();
$form = $page->form(\Filament\Forms\Form::make($page));
$sup  = $cariKomponen($form->getComponents(), 'supplier_id');

$cek('kolom supplier_id ada di skema form', $sup !== null, 'komponen tidak ditemukan');
// Bintang merahnya lahir dari required() — sama persis dengan Tgl/SPR/Nama Gimmick/Nilai
// yang bintangnya sudah merah di layar. Tidak ada penanda terpisah yang bisa lupa dipasang.
$cek('kolom Supplier wajib diisi (bintang merah)', $sup !== null && $sup->isRequired(),
    'required() tidak aktif — bintangnya tidak akan muncul');
$cek('labelnya "Supplier"', $sup !== null && $sup->getLabel() === 'Supplier',
    (string) ($sup?->getLabel() ?? 'null'));
$cek('bisa dicari (762 supplier aktif — daftar tanpa pencarian tak terpakai)',
    $sup !== null && $sup->isSearchable(), 'searchable() tidak aktif');

// Keempat kolom lama harus tetap wajib — menambah kolom tidak boleh melonggarkan yang ada.
foreach (['tgl', 'kontrak_id', 'nama', 'nilai'] as $f) {
    $k = $cariKomponen($form->getComponents(), $f);
    $cek("kolom {$f} tetap wajib", $k !== null && $k->isRequired(), 'berubah jadi opsional');
}

echo "\n=== 2. Daftar supplier = master Supplier Center BO ===\n";

$opsiForm = $sup !== null ? $sup->getOptions() : [];
$opsiBo   = GmcItem::supplierOptions();

$cek('opsinya tidak kosong', $opsiForm !== [], 'tidak ada supplier terbaca dari BO');
// SAMA PERSIS dengan yang dipakai Rincian Komponen GMC tunggal.
$cek('sama persis dengan daftar di Rincian Komponen GMC tunggal', $opsiForm === $opsiBo,
    count($opsiForm).' vs '.count($opsiBo).' opsi');

$boLangsung = DB::connection(config('ipro.connection', 'ipro_bo'))
    ->table('bs_suppliers')->where('is_active', 1)->count();
$cek('jumlahnya sama dengan supplier AKTIF di bs_suppliers', count($opsiForm) === $boLangsung,
    count($opsiForm).' vs '.$boLangsung);

$contoh = DB::connection(config('ipro.connection', 'ipro_bo'))
    ->table('bs_suppliers')->where('is_active', 1)->orderBy('name')->first(['id', 'code', 'name']);
if ($contoh) {
    $label = trim(($contoh->code ? $contoh->code.' — ' : '').$contoh->name);
    $cek('labelnya "KODE — Nama" seperti di Supplier Center',
        ($opsiForm[(int) $contoh->id] ?? null) === $label,
        (string) ($opsiForm[(int) $contoh->id] ?? 'tidak ada'));
}

echo "\n=== 3. Opsi supplier di-memoize (tak ada query per baris) ===\n";

// Halaman ini memang dibangun dengan aturan itu; daftar 762 supplier yang di-query ulang
// tiap render membuat tambah/hapus baris nyangkut.
$p2 = new GmcMassal();
DB::connection(config('ipro.connection', 'ipro_bo'))->enableQueryLog();
DB::connection(config('ipro.connection', 'ipro_bo'))->flushQueryLog();
for ($i = 0; $i < 5; $i++) {
    $p2->form(\Filament\Forms\Form::make($p2));
}
$qs = collect(DB::connection(config('ipro.connection', 'ipro_bo'))->getQueryLog())
    ->filter(fn ($q) => str_contains($q['query'], 'bs_suppliers'))->count();
DB::connection(config('ipro.connection', 'ipro_bo'))->disableQueryLog();
// GmcItem menyimpan memo statis per request, jadi setelah pemanggilan pertama di atas
// query-nya nol. Yang penting: TIDAK tumbuh mengikuti jumlah render.
$cek('lima kali render tidak menambah query bs_suppliers', $qs <= 1, $qs.' query');

echo "\n=== 4. Supplier yang dipilih benar-benar tersimpan ===\n";

DB::beginTransaction();
$sisaGmc = null;

try {
    // Database uji ini kosong — rantai company → project → lokasi → unit → kontrak dibangun
    // sendiri agar ujinya tidak bergantung pada seeder mana pun (lihat tests/Manual/README.md).
    $companyId = DB::table('companies')->insertGetId([
        'tenant_id' => 1, 'code' => 'UJIS', 'name' => 'UJI COMPANY SUPPLIER',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $projectId = DB::table('projects')->insertGetId([
        'tenant_id' => 1, 'company_id' => $companyId, 'code' => 'UJISP', 'name' => 'UJI PROJECT SUPPLIER',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $customerId = DB::table('fo_customers')->insertGetId([
        'tenant_id' => 1, 'full_name' => 'UJI CUSTOMER SUPPLIER', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $lokasi = \App\Modules\FO\MarketingSales\Models\Lokasi::create([
        'tenant_id' => 1, 'project_id' => $projectId, 'kode' => 'UJIS', 'nama' => 'UJI CLUSTER SUPPLIER',
        'is_active' => true,
    ]);
    $unit = \App\Modules\FO\MarketingSales\Models\Unit::create([
        'tenant_id' => 1, 'project_id' => $projectId, 'lokasi_id' => $lokasi->id,
        'blok' => 'US', 'no_unit' => '01',
    ]);
    $kontrak = Kontrak::create([
        'tenant_id' => 1, 'no_kontrak' => 'UJI-SPR-SUPPLIER', 'unit_id' => $unit->id,
        'customer_id' => $customerId, 'tgl_kontrak' => now()->toDateString(),
    ])->load('unit');

    if (! $kontrak) {
        echo "  LEWAT pembuatan GMC — Kontrak/SPR uji gagal dibuat\n";
    } else {
        $supplierId = (int) array_key_first($opsiForm);

        /** Jalankan create() dengan state tertentu; kembalikan pesan galatnya bila ada. */
        $jalankan = function (array $rows): ?string {
            $p = new GmcMassal();
            $p->form(\Filament\Forms\Form::make($p));
            $p->data = ['rows' => $rows];
            $r = new ReflectionMethod(GmcMassal::class, 'create');
            // Pengecualiannya DIKEMBALIKAN, bukan ditelan: redirect/notifikasi Filament di luar
            // konteks HTTP memang boleh melempar, tetapi menelan semuanya membuat kegagalan yang
            // sesungguhnya muncul sebagai "tidak ada baris tersimpan" tanpa sebab yang terbaca.
            try {
                $r->invoke($p);

                return null;
            } catch (\Throwable $e) {
                return get_class($e).': '.$e->getMessage();
            }
        };

        // (a) Baris tanpa supplier → SELURUH pengiriman ditolak validasi.
        //
        // Ini perilaku yang benar dan lebih baik daripada menyimpan sebagian: pada layar
        // massal, menyimpan baris yang sah lalu diam-diam membuang yang tidak akan membuat
        // operator mengira semuanya masuk. Yang ditolak dikembalikan ke layar dengan pesannya.
        $galat = $jalankan([
            ['tgl' => now()->toDateString(), 'kontrak_id' => $kontrak->id, 'nama' => 'UJI Gimmick Supplier',
                'supplier_id' => $supplierId, 'nilai' => 2_500_000],
            ['tgl' => now()->toDateString(), 'kontrak_id' => $kontrak->id, 'nama' => 'UJI Tanpa Supplier',
                'supplier_id' => null, 'nilai' => 100_000],
        ]);
        $cek('satu baris tanpa supplier menolak seluruh pengiriman',
            $galat !== null && str_contains($galat, 'ValidationException'), (string) $galat);
        $cek('penolakannya menyebut kolom supplier',
            $galat !== null && str_contains(strtolower($galat), 'supplier'), (string) $galat);
        $cek('tidak ada GMC yang tersimpan sebagian',
            Gmc::where('nama', 'like', 'UJI %')->doesntExist(), 'ada baris yang terlanjur tersimpan');

        // (b) Semua baris lengkap → tersimpan.
        $galat2 = $jalankan([
            ['tgl' => now()->toDateString(), 'kontrak_id' => $kontrak->id, 'nama' => 'UJI Gimmick Supplier',
                'supplier_id' => $supplierId, 'nilai' => 2_500_000],
        ]);
        if ($galat2 !== null && ! str_contains($galat2, 'Redirect')) {
            echo "  CATATAN create() melempar → {$galat2}\n";
        }

        $gmc = Gmc::where('nama', 'UJI Gimmick Supplier')->latest('id')->first();
        $cek('GMC dengan supplier tersimpan', $gmc !== null, 'tidak ada baris tersimpan');

        if ($gmc) {
            $item = $gmc->items()->first();
            // Inilah intinya: supplier harus mendarat di tempat yang DIBACA jalur Posting BO
            // (GmcResource::postGmcToBo membaca $r->items dan mengirim supplier tiap komponen).
            $cek('suppliernya tersimpan sebagai rincian komponen', $item !== null,
                'tidak ada komponen — Posting BO akan mengirim hutang tanpa supplier');
            $cek('supplier_id-nya benar', $item !== null && (int) $item->supplier_id === $supplierId,
                (string) ($item?->supplier_id ?? 'null'));
            $cek('nilai komponennya = nilai GMC-nya',
                $item !== null && (float) $item->nilai === 2_500_000.0, (string) ($item?->nilai ?? 'null'));
            // GmcResource::syncNilaiFromItems menetapkan nilai GMC = jumlah komponennya bila
            // komponennya ada. Karena layar massal kini SELALU membuat satu komponen, kedua
            // angka itu harus sama — kalau tidak, membuka lalu menyimpan GMC-nya di layar
            // tunggal akan diam-diam mengubah nilainya.
            $cek('nilai GMC = jumlah komponennya (tidak berubah saat dibuka di layar tunggal)',
                (float) $gmc->nilai === (float) $gmc->items()->sum('nilai'),
                $gmc->nilai.' vs '.$gmc->items()->sum('nilai'));
            $cek('nama supplier terbaca balik dari master BO',
                $item !== null && $item->supplierName() === ($opsiForm[$supplierId] ?? null),
                (string) ($item?->supplierName() ?? 'null'));
            // postGmcToBo memilih jalur "per komponen" hanya bila items tidak kosong.
            $cek('Posting BO akan memakai jalur per-komponen (bukan jalur tanpa supplier)',
                $gmc->items()->exists(), 'items kosong → supplier baru dipilih lagi di BO');
        }
    }
} finally {
    DB::rollBack();
    $sisaGmc = Gmc::where('nama', 'like', 'UJI %')->count();
}

$cek('data uji sudah di-rollback', $sisaGmc === 0, $sisaGmc.' baris tersisa');

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
