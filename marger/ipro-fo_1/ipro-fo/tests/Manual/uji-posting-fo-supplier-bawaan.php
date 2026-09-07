<?php

declare(strict_types=1);

/*
 * Uji supplier BAWAAN pada layar "Posting Invoice dari FO".
 *
 * BLT dan Komisi tidak membawa supplier dari FO — penerimanya agen/sales perorangan, bukan
 * vendor, dan hutangnya ditagihkan ke satu supplier penampung per jenis pembayaran:
 *
 *     BLT    (BLTPENG…) → SP.00222  PEMBAYARAN BLT
 *     Komisi (KOMPENG…) → SP.00223  PEMBAYARAN KOMISI
 *
 * Tanpa bawaan itu, setiap baris harus dipilih suppliernya satu per satu padahal jawabannya
 * selalu sama — dan salah pilih berarti hutangnya tertagih ke vendor yang keliru, ikut
 * terbawa ke Kartu Hutang dan Aging Hutang atas nama orang lain.
 *
 * YANG DIJAGA:
 *   · Baris BLT & Komisi memulangkan supplier bawaannya; layar mengisi dropdownnya dari sana.
 *   · GMC yang MEMANG punya supplier sendiri tidak ditimpa — komponennya multi-supplier.
 *   · Bawaan hanya diisikan bila suppliernya AKTIF. Supplier nonaktif tidak muncul di
 *     dropdown, jadi mengisikannya membuat kolomnya tampak terisi padahal tidak bisa disimpan.
 *   · Kode yang tidak ada di master tidak menggagalkan layarnya — barisnya sekadar tanpa
 *     bawaan, dan tetap bisa diposting setelah supplier dipilih manual.
 *   · Bawaannya benar-benar terpakai saat diposting: invoice hutangnya tertaut supplier itu.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-posting-fo-supplier-bawaan.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Web\BusinessSupport\Payable\PostingInvoiceController;
use Illuminate\Http\Request;
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

$ctrl = new PostingInvoiceController();
$antrean = function () use ($ctrl) {
    $m = new ReflectionMethod($ctrl, 'antreanFo');
    $m->setAccessible(true);

    return collect($m->invoke($ctrl));
};

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user = \App\Models\User::query()->find($userId);
    auth()->setUser($user);

    $akun = fn (string $kode) => (int) DB::table('bs_accounts')->where('code', $kode)->value('id');
    $KOM = $akun('50201010103');
    $UT  = $akun('20101010101');

    $KODE_BLT = (string) config('business_support.ap_supplier_default.blt');
    $KODE_KOM = (string) config('business_support.ap_supplier_default.komisi');

    echo "\n=== 1. Kodenya sesuai ketentuan & ada di Supplier Center ===\n";

    $cek('BLT dipetakan ke SP.00222', $KODE_BLT === 'SP.00222', $KODE_BLT);
    $cek('Komisi dipetakan ke SP.00223', $KODE_KOM === 'SP.00223', $KODE_KOM);

    $supBlt = BsSupplier::where('code', $KODE_BLT)->first();
    $supKom = BsSupplier::where('code', $KODE_KOM)->first();
    $cek('SP.00222 ada & aktif', $supBlt !== null && (bool) $supBlt->is_active,
        $supBlt ? ('aktif='.var_export((bool) $supBlt->is_active, true)) : 'tidak ada');
    $cek('SP.00223 ada & aktif', $supKom !== null && (bool) $supKom->is_active,
        $supKom ? ('aktif='.var_export((bool) $supKom->is_active, true)) : 'tidak ada');
    $cek('namanya PEMBAYARAN BLT', (string) ($supBlt->name ?? '') === 'PEMBAYARAN BLT', (string) ($supBlt->name ?? ''));
    $cek('namanya PEMBAYARAN KOMISI', (string) ($supKom->name ?? '') === 'PEMBAYARAN KOMISI', (string) ($supKom->name ?? ''));

    // ── Fikstur antrean: satu baris tiap sumber ──────────────────────────────────────────
    $antre = function (string $kind, string $refundNo, ?int $supplierId = null) use ($tenantId, $KOM, $UT) {
        return (int) DB::table('bs_ap_refund_queue')->insertGetId([
            'tenant_id' => $tenantId, 'kind' => $kind, 'refund_no' => $refundNo,
            'customer_name' => 'UJI PEMBELI', 'amount' => 1000000,
            'supplier_id' => $supplierId,
            'payable_account_id' => $UT, 'offset_account_id' => $KOM,
            'tgl_refund' => '2026-09-03', 'keterangan' => 'UJI '.$kind.' '.$refundNo,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $idBlt = $antre('blt', 'UJI-BLTPENG-S#BLT-1');
    $idKom = $antre('komisi', 'UJI-KOMPENG-S#KOM-1');

    // GMC dengan supplier sendiri — TIDAK boleh ditimpa bawaan.
    $supLain = BsSupplier::where('is_active', true)->whereNotIn('code', [$KODE_BLT, $KODE_KOM])->first();
    $idGmc = $antre('gmc', 'UJI-GMC-S-1', $supLain?->id);
    // GMC tanpa supplier — tidak punya bawaan, jadi tetap kosong (dipilih manual).
    $idGmcKosong = $antre('gmc', 'UJI-GMC-S-2');

    echo "\n=== 2. Baris BLT & Komisi membawa supplier bawaannya ===\n";

    $rows = $antrean()->keyBy('id');
    $cek('baris BLT → SP.00222', (int) ($rows[$idBlt]['supplier_id'] ?? 0) === (int) $supBlt->id,
        'dapat '.var_export($rows[$idBlt]['supplier_id'] ?? null, true));
    $cek('baris Komisi → SP.00223', (int) ($rows[$idKom]['supplier_id'] ?? 0) === (int) $supKom->id,
        'dapat '.var_export($rows[$idKom]['supplier_id'] ?? null, true));

    echo "\n=== 3. Yang sudah punya supplier tidak ditimpa ===\n";

    // Komponen GMC ditagihkan ke suppliernya masing-masing; menimpanya dengan bawaan berarti
    // seluruh gimmick tertagih ke satu supplier yang salah.
    $cek('GMC bersupplier tetap memakai suppliernya sendiri',
        (int) ($rows[$idGmc]['supplier_id'] ?? 0) === (int) $supLain->id,
        'dapat '.var_export($rows[$idGmc]['supplier_id'] ?? null, true).' harusnya '.$supLain->id);
    $cek('GMC tanpa supplier tetap kosong (tidak ada bawaan untuk gmc)',
        ($rows[$idGmcKosong]['supplier_id'] ?? null) === null,
        'dapat '.var_export($rows[$idGmcKosong]['supplier_id'] ?? null, true));

    // Baris BLT yang SUDAH membawa suppliernya sendiri juga tidak boleh ditimpa bawaan.
    // Bawaan adalah jalan pintas untuk yang kosong, bukan aturan yang mengalahkan data —
    // dan kelak bila FO mulai mengisikan supplier pada BLT, bawaan yang mendahului akan
    // membuang isian itu tanpa jejak apa pun.
    $idBltPunya = $antre('blt', 'UJI-BLTPENG-S#BLT-PUNYA', $supLain?->id);
    $rowsPunya = $antrean()->keyBy('id');
    $cek('baris BLT bersupplier sendiri tidak ditimpa bawaan',
        (int) ($rowsPunya[$idBltPunya]['supplier_id'] ?? 0) === (int) $supLain->id,
        'dapat '.var_export($rowsPunya[$idBltPunya]['supplier_id'] ?? null, true).' harusnya '.$supLain->id);

    echo "\n=== 4. Bawaannya benar-benar terpakai saat diposting ===\n";

    // Bawaan yang hanya cantik di layar tetapi tidak ikut terkirim tidak ada gunanya.
    $req = Request::create('/x', 'POST', ['supplier_id' => $rows[$idBlt]['supplier_id']]);
    $req->setUserResolver(fn () => $user);
    $ctrl->fromRefund($req, app(JournalService::class), $idBlt);
    $invId = (int) DB::table('bs_ap_refund_queue')->where('id', $idBlt)->value('ap_invoice_id');
    $inv = BsApInvoice::find($invId);
    $cek('invoice hutangnya terbit', $inv !== null);
    $cek('tertaut supplier PEMBAYARAN BLT', (int) ($inv->supplier_id ?? 0) === (int) $supBlt->id,
        'dapat '.($inv->supplier_id ?? '?'));

    echo "\n=== 5. Supplier nonaktif tidak dijadikan bawaan ===\n";

    // Supplier nonaktif tidak ada di dropdown; mengisikannya membuat kolomnya tampak terisi
    // padahal pilihannya tidak ada — dan posting massalnya gagal tanpa sebab yang terlihat.
    $supBlt->update(['is_active' => false]);
    $idBlt2 = $antre('blt', 'UJI-BLTPENG-S#BLT-2');
    $rows2 = $antrean()->keyBy('id');
    $cek('bawaan BLT dilepas saat suppliernya nonaktif',
        ($rows2[$idBlt2]['supplier_id'] ?? null) === null,
        'dapat '.var_export($rows2[$idBlt2]['supplier_id'] ?? null, true));
    $cek('bawaan Komisi tidak ikut terpengaruh',
        (int) ($rows2[$idKom]['supplier_id'] ?? 0) === (int) $supKom->id);
    $supBlt->update(['is_active' => true]);

    echo "\n=== 6. Kode yang tidak ada di master tidak merusak layarnya ===\n";

    config(['business_support.ap_supplier_default' => ['blt' => 'SP.TIDAK-ADA', 'komisi' => $KODE_KOM]]);
    $rows3 = $antrean()->keyBy('id');
    $cek('layarnya tetap memulangkan seluruh barisnya', $rows3->count() === $antrean()->count());
    $cek('baris BLT sekadar tanpa bawaan', ($rows3[$idBlt2]['supplier_id'] ?? null) === null,
        'dapat '.var_export($rows3[$idBlt2]['supplier_id'] ?? null, true));
    $cek('baris Komisi tetap berbawaan', (int) ($rows3[$idKom]['supplier_id'] ?? 0) === (int) $supKom->id);

    config(['business_support.ap_supplier_default' => []]);
    $rows4 = $antrean()->keyBy('id');
    $cek('pemetaan kosong pun tidak menggagalkan layarnya',
        ($rows4[$idKom]['supplier_id'] ?? null) === null && $rows4->count() > 0);

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
