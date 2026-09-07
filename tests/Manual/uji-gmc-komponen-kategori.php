<?php

declare(strict_types=1);

/*
 * Uji GMC tunggal ("Buat GMC (Gimmick)") tersambung ke master Setup Gimmick — sampai ke akun
 * jurnalnya saat Posting BO.
 *
 * DUDUK PERKARANYA. Saat Posting BO, akun jurnal sebuah GMC dicari dengan MENCOCOKKAN NAMA
 * PERSIS ke bs_gimmick_categories (IproApPoster::gimmickCategory), lalu akun-akunnya diambil
 * dari Setup Gimmick. Dua hal membuat rantai itu putus pada form GMC tunggal:
 *
 *   1. "Nama Komponen" pada Rincian Komponen dulu diketik BEBAS. "Jasa notaris", "PNBP ",
 *      "Biaya HT" tidak akan pernah cocok kategori mana pun, sehingga hutangnya jatuh ke akun
 *      sementara — bukan akun yang sudah ditetapkan di Setup Gimmick. Tanpa galat apa pun.
 *
 *   2. Seluruh komponen dikirim memakai nama gimmick di HEADER. Satu GMC berisi "SSP" dan
 *      "BIAYA KPR" — dua kategori dengan akun berbeda — dijurnal ke akun yang sama, dan
 *      pengaturan akun per kategori itu tidak pernah terpakai.
 *
 * Keduanya kini mengikuti logika GMC Massal: nama dipilih dari master, dan tiap komponen
 * memakai kategorinya sendiri. Nama header tetap jadi CADANGAN supaya GMC lama yang nama
 * komponennya teks bebas berperilaku persis seperti sebelumnya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — di kedua basis data.
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec jerp-verify php tests/Manual/uji-gmc-komponen-kategori.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Filament\Fo\Resources\GmcResource;
use App\Modules\FO\FinanceAR\Models\Gmc;
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
$tenantId = (int) (DB::table('fo_kontrak')->value('tenant_id') ?: 1);
$boTenant = (int) config('ipro.tenant_id', 1);
app()->instance('tenant.id', $tenantId);

DB::beginTransaction();
DB::connection($boConn)->beginTransaction();

try {
    $kontrakId = (int) DB::table('fo_kontrak')->orderBy('id')->value('id');
    if (! $kontrakId) {
        throw new RuntimeException('tidak ada kontrak untuk dijadikan fikstur');
    }
    $kontrak = DB::table('fo_kontrak')->where('id', $kontrakId)->first(['customer_id', 'unit_id']);

    // ── Dua kategori di Setup Gimmick, masing-masing akun berbeda ────────────────────────
    $akun = fn (string $kode) => (int) DB::connection($boConn)->table('bs_accounts')->where('code', $kode)->value('id');
    $AKUN_A_D = $akun('50201010103');   // beban komisi/insentif
    $AKUN_A_K = $akun('20101010101');   // utang usaha supplier
    $AKUN_B_D = $akun('50302020228');   // biaya umum & administrasi lain-lain
    $AKUN_B_K = $akun('20107070710');   // utang jk pendek lain-lain
    foreach (['50201010103' => $AKUN_A_D, '20101010101' => $AKUN_A_K, '50302020228' => $AKUN_B_D, '20107070710' => $AKUN_B_K] as $kode => $id) {
        if (! $id) {
            throw new RuntimeException("akun {$kode} tidak ada di Bagan Akun BO");
        }
    }

    $buatKategori = function (string $nama, int $debit, int $kredit) use ($boConn, $boTenant): int {
        return (int) DB::connection($boConn)->table('bs_gimmick_categories')->insertGetId([
            'tenant_id' => $boTenant, 'code' => strtoupper(str_replace(' ', '-', $nama)), 'name' => $nama,
            'debit_account_id' => $debit, 'payable_account_id' => $kredit, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $katA = $buatKategori('UJI GIMMICK A', $AKUN_A_D, $AKUN_A_K);
    $katB = $buatKategori('UJI GIMMICK B', $AKUN_B_D, $AKUN_B_K);

    /** Baris antrian BO untuk sebuah nomor GMC (termasuk turunan -1, -2, …). */
    $antrian = fn (string $noGmc) => DB::connection($boConn)->table('bs_ap_refund_queue')
        ->where('kind', 'gmc')->where(fn ($q) => $q->where('refund_no', $noGmc)->orWhere('refund_no', 'like', $noGmc.'-%'))
        ->orderBy('refund_no')->get()->all();

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Nama Komponen dipilih dari master, bukan diketik bebas ===\n";

    // (a) Sumber daftarnya — dijalankan sungguhan. Inilah yang menghubungkan form GMC ke
    //     master Setup Gimmick, dan sumbernya harus sama dengan yang dipakai GMC Massal.
    $opsiFn = new ReflectionMethod(GmcResource::class, 'gimmickOptions');
    $opsiFn->setAccessible(true);
    $opsi = $opsiFn->invoke(null);
    $cek('daftar pilihannya diambil dari master Setup Gimmick',
        isset($opsi['UJI GIMMICK A']) && isset($opsi['UJI GIMMICK B']),
        implode(', ', array_slice(array_keys($opsi), 0, 6)));

    $opsiMassal = new ReflectionMethod(\App\Filament\Fo\Resources\GmcResource\Pages\GmcMassal::class, 'gimmickOptions');
    $opsiMassal->setAccessible(true);
    $cek('daftarnya sama persis dengan GMC Massal',
        $opsiMassal->invoke(new \App\Filament\Fo\Resources\GmcResource\Pages\GmcMassal()) === $opsi);

    // (b) Bentuk fieldnya dibaca dari sumber kelasnya. Ini pemeriksaan SKEMA, bukan perilaku —
    //     disebut apa adanya. Membangun Form Filament di luar Livewire butuh perancah yang
    //     lebih rapuh daripada yang dijaganya, sementara akibat sebenarnya dari salah bentuk
    //     field ini — akun jurnal yang keliru — sudah diuji di bagian 2 lewat jalur aslinya.
    $sumber = file_get_contents((new ReflectionClass(GmcResource::class))->getFileName()) ?: '';
    $cek('Nama Komponen dideklarasikan sebagai pilihan (Select), bukan isian bebas',
        str_contains($sumber, "Select::make('nama')->label('Nama Komponen')"),
        str_contains($sumber, "TextInput::make('nama')->label('Nama Komponen')") ? 'masih TextInput' : 'tidak ditemukan');
    $cek('pilihannya dari gimmickOptions() yang sama',
        str_contains($sumber, "Select::make('nama')->label('Nama Komponen')->required()->searchable()\n                        ->options(fn () => self::gimmickOptions())"));

    echo "\n=== 2. Tiap komponen memakai akun KATEGORINYA SENDIRI ===\n";

    $gmc = Gmc::create([
        'tenant_id' => $tenantId, 'no_gmc' => 'UJI-GMC-1', 'tgl' => '2026-09-03',
        'kontrak_id' => $kontrakId, 'customer_id' => $kontrak->customer_id,
        'nama' => 'UJI GIMMICK A', 'nilai' => 3000000, 'status' => 'DRAFT',
    ]);
    $gmc->items()->create(['nama' => 'UJI GIMMICK A', 'supplier_id' => null, 'nilai' => 1000000, 'urutan' => 1]);
    $gmc->items()->create(['nama' => 'UJI GIMMICK B', 'supplier_id' => null, 'nilai' => 2000000, 'urutan' => 2]);

    GmcResource::postGmcToBo($gmc->fresh('items'));
    $baris = $antrian('UJI-GMC-1');
    $cek('2 komponen → 2 baris antrian', count($baris) === 2, 'dapat '.count($baris));

    $perNilai = [];
    foreach ($baris as $b) {
        $perNilai[(int) round((float) $b->amount)] = $b;
    }
    $cek('komponen A memakai kategori A', (int) ($perNilai[1000000]->gimmick_category_id ?? 0) === $katA,
        'kategori='.($perNilai[1000000]->gimmick_category_id ?? '?'));
    $cek('komponen B memakai kategori B — BUKAN kategori header',
        (int) ($perNilai[2000000]->gimmick_category_id ?? 0) === $katB,
        'kategori='.($perNilai[2000000]->gimmick_category_id ?? '?').' (header='.$katA.')');

    $cek('akun debit komponen A = akun kategori A',
        (int) ($perNilai[1000000]->offset_account_id ?? 0) === $AKUN_A_D,
        'dapat '.($perNilai[1000000]->offset_account_id ?? '?').' harusnya '.$AKUN_A_D);
    $cek('akun debit komponen B = akun kategori B',
        (int) ($perNilai[2000000]->offset_account_id ?? 0) === $AKUN_B_D,
        'dapat '.($perNilai[2000000]->offset_account_id ?? '?').' harusnya '.$AKUN_B_D);
    $cek('akun kredit keduanya juga mengikuti kategorinya',
        (int) ($perNilai[1000000]->payable_account_id ?? 0) === $AKUN_A_K
            && (int) ($perNilai[2000000]->payable_account_id ?? 0) === $AKUN_B_K);

    // account_split inilah yang dipakai Posting Invoice membentuk jurnalnya.
    foreach ([[1000000, $AKUN_A_D], [2000000, $AKUN_B_D]] as [$nilai, $akunDebit]) {
        $split = json_decode((string) ($perNilai[$nilai]->account_split ?? ''), true) ?: [];
        $debit = collect($split)->firstWhere('side', 'debit');
        $cek('rincian akun komponen Rp '.number_format($nilai, 0, ',', '.').' terisi dari Setup Gimmick',
            (int) ($debit['account_id'] ?? 0) === $akunDebit,
            (string) ($perNilai[$nilai]->account_split ?? '(kosong)'));
    }

    echo "\n=== 3. Nama komponen yang tak dikenal jatuh ke kategori header ===\n";

    // GMC lama nama komponennya teks bebas. Perilakunya harus PERSIS seperti sebelum
    // pencocokan per komponen ada — bukan kehilangan akunnya sama sekali.
    $gmcLama = Gmc::create([
        'tenant_id' => $tenantId, 'no_gmc' => 'UJI-GMC-2', 'tgl' => '2026-09-03',
        'kontrak_id' => $kontrakId, 'customer_id' => $kontrak->customer_id,
        'nama' => 'UJI GIMMICK A', 'nilai' => 500000, 'status' => 'DRAFT',
    ]);
    $gmcLama->items()->create(['nama' => 'Jasa notaris (ketikan bebas)', 'supplier_id' => null, 'nilai' => 500000, 'urutan' => 1]);
    GmcResource::postGmcToBo($gmcLama->fresh('items'));
    $lama = $antrian('UJI-GMC-2');
    $cek('tetap terkirim', count($lama) === 1, 'dapat '.count($lama));
    $cek('memakai kategori dari nama gimmick di header',
        (int) ($lama[0]->gimmick_category_id ?? 0) === $katA, 'kategori='.($lama[0]->gimmick_category_id ?? '?'));
    $cek('akunnya tetap dari Setup Gimmick, bukan akun sementara',
        (int) ($lama[0]->offset_account_id ?? 0) === $AKUN_A_D, 'dapat '.($lama[0]->offset_account_id ?? '?'));

    echo "\n=== 4. GMC tanpa rincian komponen tidak berubah ===\n";

    $tunggal = Gmc::create([
        'tenant_id' => $tenantId, 'no_gmc' => 'UJI-GMC-3', 'tgl' => '2026-09-03',
        'kontrak_id' => $kontrakId, 'customer_id' => $kontrak->customer_id,
        'nama' => 'UJI GIMMICK B', 'nilai' => 750000, 'status' => 'DRAFT',
    ]);
    GmcResource::postGmcToBo($tunggal->fresh('items'));
    $satu = $antrian('UJI-GMC-3');
    $cek('satu baris antrian', count($satu) === 1, 'dapat '.count($satu));
    $cek('kategorinya dari nama gimmicknya sendiri',
        (int) ($satu[0]->gimmick_category_id ?? 0) === $katB, 'kategori='.($satu[0]->gimmick_category_id ?? '?'));
    $cek('akunnya dari Setup Gimmick', (int) ($satu[0]->offset_account_id ?? 0) === $AKUN_B_D,
        'dapat '.($satu[0]->offset_account_id ?? '?'));

    echo "\n=== 5. Statusnya berpindah setelah Posting BO ===\n";

    $cek('GMC berkomponen jadi DIPROSES', Gmc::find($gmc->id)?->status === 'DIPROSES');
    $cek('GMC tunggal jadi DIPROSES', Gmc::find($tunggal->id)?->status === 'DIPROSES');

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
