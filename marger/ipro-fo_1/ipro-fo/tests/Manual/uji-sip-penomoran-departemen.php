<?php
// Uji bagian SIP yang HANYA bisa dijalankan dengan database:
//   · pengambilan urutan nomor (MAX(print_seq) + lockForUpdate) saat SIP benar-benar dibuat;
//   · tiga jalur pencarian departemen per baris, termasuk cadangan dari pembuat SIP.
// Seluruhnya di dalam transaksi lalu di-rollback.

use Illuminate\Support\Facades\DB;
use App\Domain\BusinessSupport\Models\BsSip;
use App\Domain\BusinessSupport\Services\SipService;
use App\Http\Controllers\Web\BusinessSupport\Sip\SipController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};
$isi = function (string $tabel, array $timpa = []) {
    $kolom = [];
    $isian = [];
    foreach (DB::select('SHOW COLUMNS FROM '.$tabel) as $k) {
        $kolom[$k->Field] = true;
        if ($k->Null === 'NO' && $k->Default === null && $k->Extra !== 'auto_increment') {
            $isian[$k->Field] = str_contains($k->Type, 'int') || str_contains($k->Type, 'decimal') ? 0
                : (str_contains($k->Type, 'date') || str_contains($k->Type, 'time') ? now() : 'UJI');
        }
    }

    return array_merge($isian, array_intersect_key($timpa, $kolom));
};

DB::beginTransaction();
try {
    $svc  = app(SipService::class);
    $ctrl = app(SipController::class);
    $ref  = new ReflectionMethod(SipController::class, 'renderPrint');
    $ref->setAccessible(true);
    // Relasi yang dimuat DISAMAKAN dengan SipController::print() — strict lazy loading aktif,
    // jadi memuat lebih sedikit di sini hanya akan melaporkan galat yang tidak pernah terjadi
    // di produksi, dan memuat lebih banyak akan menutupi galat yang justru nyata.
    $muat = ['cashAccount:id,code,name', 'lines.cashAccount:id,code,name', 'lines.sourceable'];
    $cetak = function (BsSip $s) use ($ref, $ctrl, $muat) {
        $s = $s->fresh();
        $s->load($muat);

        return $ref->invoke($ctrl, $s);
    };

    $tenant   = (int) (DB::table('bs_sips')->value('tenant_id') ?? 1);
    $bank     = DB::table('bs_accounts')->where('code', 'like', '101010%')->value('id') ?: DB::table('bs_accounts')->value('id');
    $userUtama = App\Models\User::first();
    $uniq     = substr((string) microtime(true), -6);

    // ── Dua departemen + dua pegawai + dua user ────────────────────────────────
    $deptBon = DB::table('bs_hr_departments')->insertGetId($isi('bs_hr_departments', [
        'tenant_id' => $tenant, 'code' => 'UJI-OPS'.$uniq, 'name' => 'DEPT UJI OPERASIONAL',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $deptInv = DB::table('bs_hr_departments')->insertGetId($isi('bs_hr_departments', [
        'tenant_id' => $tenant, 'code' => 'UJI-PRC'.$uniq, 'name' => 'DEPT UJI PURCHASING',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $deptSip = DB::table('bs_hr_departments')->insertGetId($isi('bs_hr_departments', [
        'tenant_id' => $tenant, 'code' => 'UJI-FIN'.$uniq, 'name' => 'DEPT UJI FINANCE',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $nikInv = 'NIKINV'.$uniq;
    $nikSip = 'NIKSIP'.$uniq;
    DB::table('bs_employees')->insert($isi('bs_employees', [
        'tenant_id' => $tenant, 'nik' => $nikInv, 'name' => 'Pegawai Purchasing',
        'department_id' => $deptInv, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    DB::table('bs_employees')->insert($isi('bs_employees', [
        'tenant_id' => $tenant, 'nik' => $nikSip, 'name' => 'Pegawai Finance',
        'department_id' => $deptSip, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $buatUser = fn (string $nama, ?string $nip) => DB::table('users')->insertGetId($isi('users', [
        'name' => $nama, 'email' => strtolower(str_replace(' ', '', $nama)).$uniq.'@uji.test',
        'password' => bcrypt('uji'), 'employee_nip' => $nip, 'tenant_id' => $tenant,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $userInv    = $buatUser('User Purchasing', $nikInv);   // punya kaitan pegawai
    $userSip    = $buatUser('User Finance', $nikSip);      // pembuat SIP
    $userYatim  = $buatUser('User Tanpa Pegawai', null);   // TIDAK punya kaitan pegawai

    // ── Sumber baris: satu bon sementara + dua invoice ─────────────────────────
    $supplier = DB::table('bs_suppliers')->insertGetId($isi('bs_suppliers', [
        'tenant_id' => $tenant, 'code' => 'SUP'.$uniq, 'name' => 'PT Uji Supplier',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $buatBon = fn (string $no) => DB::table('bs_cash_advances')->insertGetId($isi('bs_cash_advances', [
        'tenant_id' => $tenant, 'advance_number' => $no, 'advance_date' => now()->toDateString(),
        'holder' => 'Budi Pemegang Bon', 'purpose' => 'Uji bon', 'cash_account_id' => $bank,
        'amount' => 5000000, 'status' => 'outstanding', 'department_id' => $deptBon,
        'created_by' => $userSip, 'created_at' => now(), 'updated_at' => now(),
    ]));
    // Tiap SIP memakai sumbernya SENDIRI: satu tagihan/bon hanya boleh masuk satu SIP aktif.
    [$bon1, $bon2, $bon3] = [$buatBon('BONA'.$uniq), $buatBon('BONB'.$uniq), $buatBon('BONC'.$uniq)];
    $buatInvoice = fn (string $no, int $pembuat) => DB::table('bs_ap_invoices')->insertGetId($isi('bs_ap_invoices', [
        'tenant_id' => $tenant, 'invoice_number' => $no, 'invoice_date' => now()->toDateString(),
        'supplier_id' => $supplier, 'description' => 'Uji invoice '.$no, 'total' => 9000000,
        'paid_amount' => 0, 'status' => 'posted', 'created_by' => $pembuat,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    // pembuatnya berdepartemen
    [$invDept1, $invDept2] = [$buatInvoice('INVA'.$uniq, $userInv), $buatInvoice('INVC'.$uniq, $userInv)];
    // pembuatnya TANPA data pegawai
    [$invYatim1, $invYatim2, $invYatim3] = [$buatInvoice('INVB'.$uniq, $userYatim), $buatInvoice('INVD'.$uniq, $userYatim), $buatInvoice('INVE'.$uniq, $userYatim)];

    echo "══ Pengambilan urutan nomor dari database ══\n";

    $buatSip = fn (string $tgl, array $lines) => $svc->create([
        'sip_date' => $tgl, 'title' => 'Uji SIP', 'cash_account_id' => $bank, 'lines' => $lines,
    ], $userSip);

    $sip1 = $buatSip('2031-08-14', [['source_type' => 'adv', 'source_id' => $bon1, 'amount' => 1000, 'cash_account_id' => $bank]]);
    $ok($sip1->sip_number === '001/FIN/MITRA-SI/VIII/2031', 'SIP pertama tahun kosong → '.$sip1->sip_number);
    $ok((int) $sip1->print_seq === 1 && (int) $sip1->print_year === 2031,
        '  print_seq/print_year tersimpan: '.$sip1->print_seq.'/'.$sip1->print_year);
    $ok($sip1->printNumber() === $sip1->sip_number, '  nomor cetak = nomor dokumen');

    $sip2 = $buatSip('2031-09-02', [['source_type' => 'ap', 'source_id' => $invDept1, 'amount' => 2000, 'cash_account_id' => $bank]]);
    $ok($sip2->sip_number === '002/FIN/MITRA-SI/IX/2031', 'SIP kedua tahun sama → '.$sip2->sip_number);

    $sip3 = $buatSip('2032-01-05', [['source_type' => 'ap', 'source_id' => $invYatim1, 'amount' => 3000, 'cash_account_id' => $bank]]);
    $ok($sip3->sip_number === '001/FIN/MITRA-SI/I/2032', 'ganti tahun → urutan mulai 001 lagi: '.$sip3->sip_number);

    // SIP dihapus (soft delete) TIDAK boleh membebaskan nomornya untuk dipakai ulang.
    $sip2->delete();
    $sip4 = $buatSip('2031-11-11', [['source_type' => 'adv', 'source_id' => $bon2, 'amount' => 4000, 'cash_account_id' => $bank]]);
    $ok($sip4->sip_number === '003/FIN/MITRA-SI/XI/2031',
        'SIP terhapus tidak membebaskan nomornya — berikutnya 003, bukan 002: '.$sip4->sip_number);
    $ok(DB::table('bs_sips')->where('print_year', 2031)->whereIn('print_seq', [1, 2, 3])->count() === 3,
        '  ketiga nomor 2031 tetap terpakai satu-satu');

    echo "\n══ Pencarian departemen per baris ══\n";
    $sipCampur = $buatSip('2032-02-10', [
        ['source_type' => 'adv', 'source_id' => $bon3,          'amount' => 1000, 'cash_account_id' => $bank],
        ['source_type' => 'ap',  'source_id' => $invDept2,      'amount' => 2000, 'cash_account_id' => $bank],
        ['source_type' => 'ap',  'source_id' => $invYatim2,     'amount' => 3000, 'cash_account_id' => $bank],
    ]);
    $refDept = new ReflectionMethod(SipController::class, 'departemenPerBaris');
    $refDept->setAccessible(true);
    $sipCampurMuat = $sipCampur->fresh();
    $sipCampurMuat->load($muat);
    $dept = array_values($refDept->invoke($ctrl, $sipCampurMuat));

    $ok($dept[0] === 'DEPT UJI OPERASIONAL', '1) bon sementara → department_id-nya sendiri: '.$dept[0]);
    $ok($dept[1] === 'DEPT UJI PURCHASING', '2) invoice → departemen pembuat invoice: '.$dept[1]);
    $ok($dept[2] === 'DEPT UJI FINANCE', '3) invoice tanpa jejak pegawai → CADANGAN departemen pembuat SIP: '.$dept[2]);

    echo "\n══ Cetakan memakai nama itu ══\n";
    $html = $cetak($sipCampur);
    foreach (['DEPT UJI OPERASIONAL', 'DEPT UJI PURCHASING', 'DEPT UJI FINANCE'] as $n) {
        $ok(substr_count($html, $n) >= 2, "  '{$n}' muncul di judul kelompok DAN kolom barisnya (".substr_count($html, $n).'x)');
    }
    $ok(! str_contains($html, 'Tanpa Departemen'), '  tidak ada baris yang jatuh ke "Tanpa Departemen"');
    $ok(str_contains($html, $sipCampur->sip_number) && ! str_contains($html, 'No. Dokumen:'),
        '  kepala dokumen memuat satu nomor saja: '.$sipCampur->sip_number);

    // Pembuat SIP tanpa data pegawai → cadangannya ikut kosong, labelnya kembali apa adanya.
    $sipYatim = $svc->create([
        'sip_date' => '2032-03-03', 'title' => 'Uji', 'cash_account_id' => $bank,
        'lines' => [['source_type' => 'ap', 'source_id' => $invYatim3, 'amount' => 1500, 'cash_account_id' => $bank]],
    ], $userYatim);
    $sipYatimMuat = $sipYatim->fresh();
    $sipYatimMuat->load($muat);
    $d2 = array_values($refDept->invoke($ctrl, $sipYatimMuat));
    $ok($d2[0] === 'Tanpa Departemen', 'pembuat SIP pun tanpa departemen → "Tanpa Departemen", bukan kosong/galat');

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);
