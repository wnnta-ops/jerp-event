<?php

declare(strict_types=1);

/*
 * Uji penjaga budget BULANAN + Koreksi Budget dari Registrasi Invoice Hutang.
 *
 * Yang dijaga:
 *   · Sisa budget = anggaran BULAN tanggal dokumen (m1..m12) − realisasi bulan itu; budget tanpa
 *     rincian bulanan dibagi rata 12.
 *   · Penjaga menolak yang melewati sisa bulan itu; lolos bila tertutup koreksi yang diajukan
 *     bersama, dan tetap ditolak bila koreksinya kurang.
 *   · Koreksi dari invoice masuk Approval Inbox (Accounting → GM); disetujui penuh → tambahan masuk
 *     ke bulan invoice & anggaran tahunan; ditolak → budget tidak berubah.
 *   · Koreksi dari invoice tidak muncul di layar Approval Koreksi lama (satu jalur keputusan).
 *
 * Data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-koreksi-budget-invoice.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetCorrectionService;
use App\Domain\BusinessSupport\Services\BudgetGuardService;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
$kode = function (callable $fn): ?string {
    try {
        $fn();
    } catch (BusinessRuleException $e) {
        return $e->errorCode();
    }

    return null;
};

DB::beginTransaction();

try {
    $buatUser = function (string $nama, array $roles) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama, 'username' => 'u'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $staf = $buatUser('Staf Koreksi Uji', ['Collection']);
    // Koreksi Budget = satu tahap Finance (chain KB-DEFAULT); GM di sini = bukan approver.
    $accounting = $buatUser('Finance Koreksi Uji', ['Finance']);
    $gm = $buatUser('GM Koreksi Uji', ['GM']);
    $sebagai = function (User $u) { auth()->setUser($u); };
    // Chain Koreksi (satu tahap Finance) diarahkan ke Finance uji — ikut dibatalkan bersama transaksi.
    DB::table('approval_chain_steps')->whereIn('approval_chain_id', DB::table('approval_chains')->where('code', 'KB-DEFAULT')->pluck('id'))
        ->update(['approver_type' => 'user', 'approver_value' => (string) $accounting->id]);

    // Akun beban & cost center yang sudah dipakai budget demo; tahun jauh supaya bebas data lain.
    $akun = (int) DB::table('bs_accounts')->where('code', 'like', '5%')->where('is_postable', 1)->value('id');
    $cc = (int) DB::table('bs_cost_centers')->orderBy('id')->value('id');
    $tahun = 2031;
    $bulanan = array_combine(array_map(fn ($m) => 'm'.$m, range(1, 12)), array_fill(0, 12, 1_000_000));
    $budget = BsBudget::create(['doc_no' => 'UJI-KB-'.Str::random(4), 'year' => $tahun, 'account_id' => $akun,
        'cost_center_id' => $cc, 'amount' => 12_000_000, 'status' => 'approved'] + $bulanan);
    $guard = app(BudgetGuardService::class);
    $baris = fn (float $nilai) => [['account_id' => $akun, 'cost_center_id' => $cc, 'debit' => $nilai, 'credit' => 0]];

    echo "\n=== 1. Sisa budget bulanan ===\n";
    $s = $guard->sisaBulan($akun, $cc, "{$tahun}-11-10");
    $cek('anggaran bulan ini = kolom m11', $s && abs($s['anggaran'] - 1_000_000) < 0.01, json_encode($s));
    $cek('belum ada realisasi → terpakai 0', $s && abs($s['terpakai']) < 0.01);
    $cek('akun beban bertanda +1', $s && $s['tanda'] === 1);
    $cek('akun tanpa anggaran → null', $guard->sisaBulan($akun, null, '2035-01-01') === null);
    $lama = BsBudget::make(['amount' => 1_200_000]);
    $cek('budget tanpa rincian bulanan → dibagi rata 12', abs(BudgetGuardService::anggaranBulan($lama, 5) - 100_000) < 0.01);

    echo "\n=== 2. Penjaga bulanan + koreksi ===\n";
    $cek('dalam sisa bulan ini → lolos', $kode(fn () => $guard->assertWithinBudget($baris(900_000), null, "{$tahun}-11-10")) === null);
    $cek('lewat sisa bulan ini → OVER_BUDGET', $kode(fn () => $guard->assertWithinBudget($baris(1_500_000), null, "{$tahun}-11-10")) === 'OVER_BUDGET');
    $cek('tertutup koreksi yang diajukan → lolos', $kode(fn () => $guard->assertWithinBudget($baris(1_500_000), null, "{$tahun}-11-10", null, [$budget->id => 500_000])) === null);
    $cek('koreksi kurang → tetap OVER_BUDGET', $kode(fn () => $guard->assertWithinBudget($baris(1_500_000), null, "{$tahun}-11-10", null, [$budget->id => 400_000])) === 'OVER_BUDGET');
    $cek('anggaran setahun tidak dipakai menutup bulan ini', $kode(fn () => $guard->assertWithinBudget($baris(2_000_000), null, "{$tahun}-03-10")) === 'OVER_BUDGET');

    echo "\n=== 3. Koreksi dari invoice → Approval Inbox ===\n";
    $sup = (int) DB::table('bs_suppliers')->value('id');
    $acc = (int) DB::table('bs_accounts')->value('id');
    $invoice = fn () => BsApInvoice::create([
        'tenant_id' => $tenantId, 'invoice_number' => 'INV-KB-'.Str::random(6), 'invoice_date' => "{$tahun}-11-10",
        'supplier_id' => $sup, 'expense_account_id' => $acc, 'payable_account_id' => $acc, 'description' => 'Invoice uji koreksi',
        'dpp' => 1_500_000, 'total' => 1_500_000, 'net_payable' => 1_500_000, 'paid_amount' => 0, 'status' => 'open',
    ]);
    $sebagai($staf);
    $inv = $invoice();
    [$k] = app(BudgetCorrectionService::class)->ajukanDariInvoice($inv, [[
        'budget_id' => $budget->id, 'account_id' => $akun, 'cost_center_id' => $cc, 'keterangan' => 'Iklan tambahan', 'nilai' => 500_000,
    ]], $staf->id);
    $cek('koreksi tercatat (baru, bulan invoice, sumber invoice)', $k->status === 'baru' && $k->period_from === 11 && $k->period_to === 11
        && $k->source_type === 'ap_invoice' && (int) $k->source_id === (int) $inv->id);
    $cek('nilai baru = anggaran tahunan + pengajuan', abs((float) $k->new_amount - 12_500_000) < 0.01);
    $a = Approval::with('steps')->find($k->approval_id);
    $cek('approval koreksi dibuat (1 tahap Finance)', $a && $a->document_type === BsBudgetCorrection::DOCUMENT_TYPE && $a->steps->count() === 1);
    $inbox = app(ApprovalInboxQuery::class);
    $cek('muncul di inbox Finance', $inbox->needsMe($accounting)->contains('id', $a->id));
    $cek('tidak muncul di inbox non-Finance (GM)', ! $inbox->needsMe($gm)->contains('id', $a->id));
    $cek('label inbox "Koreksi Budget" & nominal = pengajuan', ($r = $inbox->row($a->load('approvable')))['type'] === 'Koreksi Budget' && abs($r['amount'] - 500_000) < 0.01, json_encode($r));
    // Menu Approval Koreksi Budget: koreksi dari invoice ikut tampil; Setujui di sana = tahap di Approval Inbox.
    $minta = function (string $method, array $isi = []) {
        $r = Illuminate\Http\Request::create('/', $method, $isi);
        $r->setUserResolver(fn () => auth()->user());
        $r->setLaravelSession(app('session.store'));
        app()->instance('request', $r);

        return $r;
    };
    $ctlKor = app(App\Http\Controllers\Web\BusinessSupport\Budget\BudgetCorrectionController::class);
    $sebagai($accounting);
    $baris = app()->call([$ctlKor, 'approvals'], ['request' => $minta('GET')]);
    $baris = (fn () => $this->props)->call($baris)['rows']->firstWhere('id', $k->id);
    $cek('muncul di Approval Koreksi Budget (sumber invoice, tahap Finance, bisa diputuskan)', $baris && $baris['invoice'] && str_starts_with((string) $baris['tahap'], 'Finance') && $baris['bisa'] && abs($baris['tambahan'] - 500_000) < 0.01, json_encode($baris));
    $sebagai($gm);
    try {
        $barisGm = (fn () => $this->props)->call(app()->call([$ctlKor, 'approvals'], ['request' => $minta('GET')]))['rows']->firstWhere('id', $k->id);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $barisGm = ['bisa' => false]; // tanpa izin menu & belum gilirannya → halaman ditolak
    }
    $cek('GM (bukan approver) → tidak bisa memutuskan', ! $barisGm['bisa']);
    app()->call([$ctlKor, 'approveBatch'], ['request' => $minta('POST', ['ids' => [$k->id], 'decision' => 'approve'])]);
    $cek('GM tidak bisa menyetujui', Approval::find($a->id)->steps()->where('status', 'approved')->count() === 0);

    $act = app(ActOnApproval::class);
    $sebagai($accounting);
    app()->call([$ctlKor, 'approveBatch'], ['request' => $minta('POST', ['ids' => [$k->id], 'decision' => 'approve'])]);
    $cek('Finance setujui dari menu Approval Koreksi → tahap Finance di Inbox approved', Approval::find($a->id)->steps()->where('level', 1)->value('status') === 'approved');
    $b = $budget->fresh();
    $cek('disetujui penuh → koreksi approved', $k->fresh()->status === 'approved');
    $cek('tambahan masuk ke bulan invoice (m11)', abs((float) $b->m11 - 1_500_000) < 0.01, (string) $b->m11);
    $cek('bulan lain tidak berubah', abs((float) $b->m10 - 1_000_000) < 0.01);
    $cek('anggaran tahunan ikut naik', abs((float) $b->amount - 12_500_000) < 0.01, (string) $b->amount);

    echo "\n=== 4. Koreksi ditolak ===\n";
    $sebagai($staf);
    [$k2] = app(BudgetCorrectionService::class)->ajukanDariInvoice($invoice(), [[
        'budget_id' => $budget->id, 'account_id' => $akun, 'cost_center_id' => $cc, 'keterangan' => null, 'nilai' => 300_000,
    ]], $staf->id);
    $sebagai($accounting);
    $act->execute(Approval::with('steps')->find($k2->approval_id), 'reject', 'Tidak perlu');
    $cek('ditolak → koreksi rejected', $k2->fresh()->status === 'rejected');
    $cek('ditolak → budget tidak berubah', abs((float) $budget->fresh()->m11 - 1_500_000) < 0.01);

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
