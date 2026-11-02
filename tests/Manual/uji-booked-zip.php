<?php

// Uji ZIP berkas Verifikasi Booked (satu & massal). Semua data dibatalkan (rollback).
// docker exec jerp-app php artisan tinker tests/Manual/uji-booked-zip.php

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Controllers\CustomerFileController;
use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$disk = Storage::disk('local');
$tulis = [];
DB::beginTransaction();
try {
    $reg = NupRegistration::withoutGlobalScopes()->whereNotNull('customer_id')->with('customer')->take(2)->get();
    if ($reg->count() < 2) {
        throw new RuntimeException('Butuh 2 pendaftar ber-customer di DB lokal.');
    }
    app(\App\Support\TenantContext::class)->set((int) $reg[0]->tenant_id);
    foreach ($reg as $i => $r) {
        foreach (["uji/ktp{$i}.jpg" => 'ktp_file_path', "uji/npwp{$i}.pdf" => 'npwp_file_path'] as $p => $kol) {
            $disk->put($p, "isi {$p}");
            $tulis[] = $p;
            Customer::withoutGlobalScopes()->whereKey($r->customer_id)->update([$kol => $p]);
        }
        $disk->put($b = "uji/utj{$i}.png", 'bukti');
        $tulis[] = $b;
        NupRegistration::withoutGlobalScopes()->whereKey($r->id)->update(['bukti_transfer_path' => $b]);
    }

    foreach ([[$reg[0]->id], $reg->pluck('id')->all()] as $ids) {
        request()->merge(['ids' => implode(',', $ids)]);
        $res = app(CustomerFileController::class)->bookedZip();
        $z = new ZipArchive;
        $z->open($res->getFile()->getPathname());
        echo $res->headers->get('content-disposition'), PHP_EOL;
        for ($k = 0; $k < $z->numFiles; $k++) {
            echo '   ', $z->getNameIndex($k), PHP_EOL;
        }
        $z->close();
    }
} finally {
    DB::rollBack();
    $disk->delete($tulis);
}
