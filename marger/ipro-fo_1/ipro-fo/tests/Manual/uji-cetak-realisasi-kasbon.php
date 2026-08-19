<?php
// Uji cetak REALISASI BON SEMENTARA: format Refund/Match vs Reimburse.
//
// Refund & Match memakai formulir yang sama; Reimburse berbeda pada total (dalam kurung),
// label No. Voucher, dan blok tanda tangannya (satu kolom lebih).
//
// Dibungkus transaksi lalu di-rollback.

use Illuminate\Support\Facades\DB;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};
$isi = function (string $t, array $x = []) {
    $k = [];
    $i = [];
    foreach (DB::select('SHOW COLUMNS FROM '.$t) as $c) {
        $k[$c->Field] = true;
        if ($c->Null === 'NO' && $c->Default === null && $c->Extra !== 'auto_increment') {
            $i[$c->Field] = str_contains($c->Type, 'int') || str_contains($c->Type, 'decimal') ? 0
                : (str_contains($c->Type, 'date') || str_contains($c->Type, 'time') ? now() : 'UJI');
        }
    }

    return array_merge($i, array_intersect_key($x, $k));
};

DB::beginTransaction();
try {
    $ctrl = app(CashAdvanceController::class);
    $ref  = new ReflectionMethod(CashAdvanceController::class, 'renderRealisasiPrint');
    $ref->setAccessible(true);

    $bank  = DB::table('bs_accounts')->where('code', 'like', '101010%')->value('id');
    $beban = DB::table('bs_accounts')->where('type', 'expense')->where('is_postable', true)->value('id');
    $dept  = DB::table('bs_hr_departments')->value('id');
    $u     = substr((string) microtime(true), -5);

    $buat = function (float $amount, float $realisasi) use ($isi, $bank, $beban, $dept, $u) {
        $id = DB::table('bs_cash_advances')->insertGetId($isi('bs_cash_advances', [
            'tenant_id' => 1, 'advance_number' => 'BON/UJI/'.$u.'/'.(int) $realisasi,
            'advance_date' => now()->toDateString(), 'realized_date' => now()->toDateString(),
            'holder' => 'CITRA LESTARI', 'purpose' => 'kasbon uji cetak',
            'cash_account_id' => $bank, 'department_id' => $dept,
            'amount' => $amount, 'realized_amount' => $realisasi,
            'settlement' => $amount - $realisasi, 'status' => 'realized',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        DB::table('bs_cash_advance_lines')->insert($isi('bs_cash_advance_lines', [
            'cash_advance_id' => $id, 'expense_account_id' => $beban,
            'description' => 'BIAYA UJI', 'amount' => $realisasi,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return BsCashAdvance::withoutGlobalScopes()->with(['cashAccount:id,code,name', 'department:id,code,name', 'lines.expenseAccount:id,code,name'])->find($id);
    };
    $cetak = fn ($a) => $ref->invoke($ctrl, $a);

    echo "══ Penanda cetak ══\n";
    $h = $cetak($buat(100000, 80000));
    $ok(str_contains($h, 'Created By Java Core'), 'tertulis "Created By Java Core"');
    $ok(! str_contains($h, 'eProperty'), '  sebutan eProperty sudah tidak ada');

    echo "\n══ REFUND (realisasi lebih kecil) ══\n";
    $ok(str_contains($h, 'REALISASI BON SEMENTARA'), 'judul formulirnya benar');
    $ok(str_contains($h, 'Rp. 20,000.00'), '  Total = sisa Rp. 20,000.00 TANPA kurung');
    $ok(! str_contains($h, '(Rp. 20,000.00)'), '  tidak dikurung');
    $ok(str_contains($h, 'No. Voucher : -'), '  label "No. Voucher : -"');
    $ok(str_contains($h, 'Di Terima Oleh,'), '  ada judul "Di Terima Oleh,"');
    $ok(! str_contains($h, 'Di Bayarkan Oleh,'), '  TIDAK ada "Di Bayarkan Oleh," (khusus reimburse)');
    $ok(substr_count($h, '<td>Direksi</td>') === 1, '  peran Direksi ada');
    $ok(stripos($h, 'Mitra Usaha Propertindo') !== false, '  kolom terakhir ditandatangani perusahaan');
    $ok(! str_contains($h, '<td>Finance</td>'), '  tanpa kolom Finance');

    echo "\n══ MATCH (nilainya sama) → formulir REFUND ══\n";
    $hm = $cetak($buat(100000, 100000));
    $ok(str_contains($hm, 'No. Voucher : -'), 'memakai formulir refund');
    $ok(! str_contains($hm, 'Di Bayarkan Oleh,'), '  blok TTD sama dengan refund');
    $ok(str_contains($hm, 'Rp. 0.00'), '  selisihnya nol dan dicetak apa adanya');
    $ok(stripos($hm, 'Mitra Usaha Propertindo') !== false, '  kolom terakhir tetap perusahaan');

    echo "\n══ REIMBURSE (realisasi lebih besar) ══\n";
    $hr = $cetak($buat(100000, 130000));
    $ok(str_contains($hr, '(Rp. 30,000.00)'), 'Total DIKURUNG: (Rp. 30,000.00)');
    $ok(str_contains($hr, 'Di Bayarkan Oleh,'), '  judul "Di Bayarkan Oleh," muncul');
    $ok(str_contains($hr, '<td>Finance</td>'), '  ada kolom Finance yang membayarkan');
    $ok(str_contains($hr, '<td>CITRA LESTARI</td>'), '  kolom terakhir = pemegang bon yang menerima');
    $ok(stripos($hr, 'Mitra Usaha Propertindo</td>') === false, '  bukan perusahaan yang menerima');
    $ok(! str_contains($hr, 'No. Voucher : -'), '  label "No. Voucher" tanpa " : -"');

    echo "\n══ Bagian yang sama di kedua formulir ══\n";
    foreach (['Dibayarkan Kepada', 'Dibayar Via', 'Cara Pembayaran Bank', 'No. Rekening',
        'No.Account', 'Uraian Pembayaran', 'Jumlah', 'No. Cek / BG', 'Terbilang',
        'Diajukan Oleh,', 'Di Verifikasi Oleh,', 'Di Setujui Oleh,',
        'SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager'] as $bagian) {
        $ok(str_contains($h, $bagian) && str_contains($hr, $bagian), '  "'.$bagian.'" ada di keduanya');
    }
    $ok(str_contains($h, 'kasbon uji cetak') && str_contains($h, 'BIAYA UJI'),
        'baris pengajuan & baris realisasi keduanya tercetak');

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);
