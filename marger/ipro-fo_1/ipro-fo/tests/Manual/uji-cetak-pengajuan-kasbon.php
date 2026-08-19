<?php
// Uji pemilihan & isi formulir Cetak Pengajuan bon sementara.
//
// Di bawah Rp 1 juta → satu halaman "KAS BON".
// Mulai Rp 1 juta   → dua halaman "BUKTI PERMOHONAN PEMBAYARAN" + "MEMO INTERNAL".
//
// Dibungkus transaksi lalu di-rollback: tidak meninggalkan satu baris pun di database.

use Illuminate\Support\Facades\DB;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Print\PengajuanKasbonPrinter;

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
    $printer = app(PengajuanKasbonPrinter::class);
    $user    = App\Models\User::find(1);
    $tenant  = (int) (DB::table('bs_cash_advances')->value('tenant_id') ?? 1);
    $bank    = DB::table('bs_accounts')->where('code', 'like', '101010%')->value('id');
    $dept    = DB::table('bs_hr_departments')->value('id');
    $emp     = DB::table('bs_employees')->first();
    $uniq    = substr((string) microtime(true), -5);

    // Pemegang bon dibuat punya rekening supaya bagian "Dibayarkan kepada" terisi.
    DB::table('bs_employees')->where('id', $emp->id)->update(['bank_name' => 'BCA', 'bank_account' => '5535061570']);

    $buat = fn (float $nominal) => BsCashAdvance::findOrFail(DB::table('bs_cash_advances')->insertGetId($isi('bs_cash_advances', [
        'tenant_id' => $tenant, 'advance_number' => 'BON/UJI/'.$uniq.'/'.(int) $nominal,
        'advance_date' => now()->toDateString(), 'holder' => $emp->name,
        'purpose' => 'PENGAJUAN KASBON I PRODUCT KNOWLEDGE I CLUSTER 5 TAHAP 2',
        'cash_account_id' => $bank, 'department_id' => $dept, 'amount' => $nominal,
        'status' => 'requested', 'created_by' => $user?->id,
        'created_at' => now(), 'updated_at' => now(),
    ])));

    echo "══ Di BAWAH Rp 1 juta → KAS BON ══\n";
    $kecil = $buat(999_999);
    $h = $printer->render($kecil->load(['cashAccount:id,code,name', 'department:id,code,name']));
    $ok(str_contains($h, 'KAS BON'), 'judul "KAS BON" tercetak');
    $ok(! str_contains($h, 'BUKTI PERMOHONAN PEMBAYARAN'), '  BPP TIDAK ikut tercetak');
    $ok(! str_contains($h, 'MEMO INTERNAL'), '  Memo Internal TIDAK ikut tercetak');
    foreach (['Tanggal', 'Nomor', 'Bagian', 'Project', 'Non Project', 'Uraian', 'Jumlah',
        'Dibayarkan kepada', 'No. Rekening', 'Catatan', 'Total'] as $bagian) {
        $ok(str_contains($h, $bagian), '  bagian "'.$bagian.'" ada');
    }
    foreach (['Yang Mengajukan', 'Diverifikasi', 'Disetujui', 'Dibayar', 'Diterima'] as $t) {
        $ok(str_contains($h, $t), '  kolom TTD "'.$t.'" ada');
    }
    foreach (['SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager', 'Direktur'] as $t) {
        $ok(str_contains($h, $t), '  peran "'.$t.'" ada');
    }
    $ok(str_contains($h, 'Rp. 999.999,00'), '  nominal bergaya KAS BON (titik ribuan, koma desimal)');
    $ok(str_contains($h, '5535061570') && str_contains($h, 'BCA'), '  rekening pemegang bon terbawa');

    echo "\n══ TEPAT Rp 1 juta → formulir BESAR ══\n";
    $pas = $buat(1_000_000);
    $h2 = $printer->render($pas->load(['cashAccount:id,code,name', 'department:id,code,name']));
    $ok(str_contains($h2, 'BUKTI PERMOHONAN PEMBAYARAN'), 'tepat 1 juta memakai BPP, bukan KAS BON');
    $ok(! str_contains($h2, '>KAS BON<'), '  judul KAS BON tidak muncul');

    echo "\n══ Di ATAS Rp 1 juta → BPP + MEMO INTERNAL ══\n";
    $besar = $buat(16_500_000);
    $h3 = $printer->render($besar->load(['cashAccount:id,code,name', 'department:id,code,name']));
    $ok(str_contains($h3, 'BUKTI PERMOHONAN PEMBAYARAN'), 'halaman 1: BPP');
    $ok(str_contains($h3, 'MEMO INTERNAL'), 'halaman 2: MEMO INTERNAL');
    $ok(substr_count($h3, 'class="hal"') === 1, '  ada pemisah halaman antara keduanya');
    foreach (['Diajukan Tanggal', 'Departemen', 'Dibayarkan Kepada', 'Dibayar Via', 'Type Pengeluaran',
        'Cara Pembayaran Bank', 'Lampiran', 'No. Account', 'Uraian Pembayaran',
        'No. Cek / BG', 'No. Voucher', 'Terbilang'] as $bagian) {
        $ok(str_contains($h3, $bagian), '  BPP: bagian "'.$bagian.'" ada');
    }
    foreach (['Diajukan Oleh,', 'Di Verifikasi Oleh,', 'Di Setujui Oleh,', 'Di Bayarkan Oleh,', 'Di Terima Oleh,'] as $t) {
        $ok(str_contains($h3, $t), '  BPP: judul TTD "'.$t.'" ada');
    }
    foreach (['General Cashier', 'Penerima'] as $t) {
        $ok(str_contains($h3, $t), '  BPP: peran "'.$t.'" ada (7 kotak di bawah 5 judul)');
    }
    foreach (['Kepada Yth', 'Tembusan', 'Hari / Tanggal', 'Perihal', 'Dengan hormat,',
        'Nama Barang', 'HARGA SAT. (Rp)', 'JML HARGA', 'TOTAL',
        'Pembayaran dilakukan melalui transfer Rekening', 'Atas Nama',
        'Demikian ini kami sampaikan', 'Hormat Kami,', 'Menyetujui,', 'Mengetahui,'] as $bagian) {
        $ok(str_contains($h3, $bagian), '  Memo: bagian "'.$bagian.'" ada');
    }
    $ok(str_contains($h3, 'Rp. 16,500,000.00'), '  BPP/Memo memakai gaya angka Rp. 16,500,000.00');
    $ok(str_contains($h3, 'ENAM BELAS JUTA LIMA RATUS RIBU RUPIAH'),
        '  terbilang benar: ENAM BELAS JUTA LIMA RATUS RIBU RUPIAH');
    $ok(substr_count($h3, $besar->advance_number) >= 2, '  nomor bon muncul di BPP dan Memo');

    echo "\n══ Ambangnya satu, dipakai bersama ══\n";
    $ok(PengajuanKasbonPrinter::AMBANG_BESAR === 1_000_000, 'ambang dinyatakan sekali: '.PengajuanKasbonPrinter::AMBANG_BESAR);

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(transaksi di-rollback — tidak ada data tersisa)\n";
}
exit($gagal ? 1 : 0);
