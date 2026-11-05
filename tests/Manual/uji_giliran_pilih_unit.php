<?php
// Uji alur giliran Pilih Unit (dalam transaksi, di-ROLLBACK). Jalankan: php tests/Manual/uji_giliran_pilih_unit.php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\FO\Launching\Models\PilihUnitSetup;
use App\Modules\FO\Launching\Services\PilihUnitGiliran;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Support\Facades\DB;

$g = app(PilihUnitGiliran::class);
$cek = function (string $nama, bool $ok) { echo ($ok ? 'OK   ' : 'GAGAL').' '.$nama."\n"; };

DB::beginTransaction();
try {
    $lok = Lokasi::query()->whereNotIn('id', PilihUnitSetup::withTrashed()->pluck('lokasi_id'))->first();
    $id = $lok->id;
    $cek('tanpa setup: giliran nonaktif, timer 2 menit', $g->status($id) == ['aktif' => false, 'jumlah' => 0, 'giliran' => null, 'terpakai' => [], 'timer_aktif' => true, 'timer_menit' => 2]);

    PilihUnitSetup::create(['lokasi_id' => $id, 'giliran_aktif' => true, 'jumlah_hp' => 3, 'timer_aktif' => false, 'timer_menit' => 5]);
    $st = $g->status($id);
    $cek('setup: aktif, 3 HP, giliran HP 1, tanpa timer', $st['aktif'] && $st['jumlah'] === 3 && $st['giliran'] === 1 && ! $st['timer_aktif']);

    $cek('HP A pegang 1', $g->pegang($id, 1, 'A'));
    $cek('HP B tak bisa pegang 1', ! $g->pegang($id, 1, 'B'));
    $cek('HP A perpanjang 1', $g->pegang($id, 1, 'A'));
    $cek('HP B pegang 2', $g->pegang($id, 2, 'B'));
    $cek('terpakai [1,2]', $g->hpTerpakai($id, 3) === [1, 2]);

    try { $g->pastikanGiliran($id, 2); $cek('HP 2 ditolak saat giliran HP 1', false); } catch (RuntimeException $e) { $cek('HP 2 ditolak: '.$e->getMessage(), true); }
    try { $g->pastikanGiliran($id, 1); $cek('HP 1 boleh', true); } catch (RuntimeException $e) { $cek('HP 1 boleh', false); }

    $cek('HP 1 memilih → giliran HP 2', $g->lanjut($id, 1) === 2);
    $cek('maju ganda dari HP 1 diabaikan', $g->lanjut($id, 1) === 2);
    $cek('Lanjut admin → HP 3', $g->lanjut($id) === 3);
    $cek('HP 3 memilih → kembali HP 1', $g->lanjut($id, 3) === 1);

    $s = PilihUnitSetup::where('lokasi_id', $id)->first();
    $s->update(['giliran_hp' => 3]);
    $s->update(['jumlah_hp' => 2]);
    $cek('jumlah HP dikurangi di bawah giliran → HP 1', $s->fresh()->giliran_hp === 1);

    $g->lepas($id, 1, 'B');
    $cek('lepas oleh pemegang lain diabaikan', $g->hpTerpakai($id, 3) === [1, 2]);
    $g->lepas($id, 1, 'A');
    $g->lepas($id, 2, 'B');
    $cek('lepas oleh pemegang', $g->hpTerpakai($id, 3) === []);
} finally {
    DB::rollBack();
    echo "ROLLBACK\n";
}
