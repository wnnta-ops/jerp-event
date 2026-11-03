<?php

/**
 * Uji Memo Urgent dibayar di Registrasi Pengeluaran Kas & Bank (AP) sebelum invoice hutang:
 *  1. Memo bisa dibayar setelah Direktur menyetujui; sekali bayar, maks. nominal memo, akun uang muka 10107…
 *     Jurnal Dr Uang Muka / Cr Kas/Bank, kuitansi langsung aktif.
 *  2. Belum ada invoice → masuk daftar outstanding; lewat batas hari → pengaju tidak bisa ajukan memo baru.
 *  3. TTD cetakan: Direktur tertanda, MW / BA menyusul otomatis setelah disetujui.
 *  4. Invoice penyusul: lebih kecil dari yang dibayar → ditolak; dibayar di Kuitansi → potong uang muka wajib
 *     (Dr Hutang / Cr Uang Muka / Cr Kas sisanya); kuitansi memo tak bisa di-void setelah uang mukanya terpakai.
 *
 * Data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-memo-urgent-bayar.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\ApKuitansiService;
use App\Domain\BusinessSupport\Services\PembayaranMemoUrgent;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\ApprovalInbox\ApprovalInboxController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApKuitansiController;
use App\Models\User;
use Illuminate\Http\Request;
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
$sama = fn ($a, $b) => abs((float) $a - (float) $b) < 0.01;
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
    $admin = User::where('email', '1234@jerp.test')->firstOrFail();
    $pengaju = $buatUser('Pengaju Memo Bayar Uji', ['Collection']);
    $approver = [];
    foreach (['Accounting', 'Finance', 'GM', 'Direktur', 'Owner'] as $r) {
        $approver[$r] = $buatUser("{$r} Memo Bayar Uji", [$r]);
    }
    $sebagai = function (User $u) { auth()->setUser($u); };
    $minta = function (string $method, array $isi = []) {
        $r = Request::create('/uji', $method, $isi, [], [], ['HTTP_REFERER' => url('/approval-inbox')]);
        $r->setLaravelSession(app('session.store'));
        $r->setUserResolver(fn () => auth()->user());
        app()->instance('request', $r);

        return $r;
    };
    $sesi = function (string $k) {
        $v = app('session.store')->get($k);
        app('session.store')->forget($k);

        return (string) $v;
    };
    $act = app(ActOnApproval::class);
    $setujui = function (int $approvalId, array $roles) use ($act, $approver, $sebagai) {
        foreach ($roles as $r) {
            $sebagai($approver[$r]);
            $act->execute(Approval::with('steps')->find($approvalId), 'approve');
        }
    };
    $svc = app(PembayaranMemoUrgent::class);
    $supplier = (int) DB::table('bs_suppliers')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->value('id');
    $bank = (int) DB::table('bs_accounts')->where('is_active', 1)->where('is_postable', 1)->where('code', 'like', '101010%')
        ->whereNotIn('code', ['10101010101', '10101010102'])->orderBy('code')->value('id');
    $akunUm = (int) $svc->akunUangMuka()->first()->id;
    $akunBukanUm = $bank;
    $hutang = (int) DB::table('bs_ap_invoices')->whereNotNull('payable_account_id')->value('payable_account_id');
    $tgl = now()->toDateString();

    echo "=== 1. Bayar Memo Urgent ===\n";
    $sebagai($pengaju);
    $memo = app(SubmitMemoInternal::class)->execute(['memo_date' => $tgl, 'department' => 'Uji', 'supplier_id' => $supplier,
        'subject' => 'Memo bayar uji', 'body' => 'Isi', 'amount' => 1_000_000]);
    $cek('memo belum sampai Direktur → belum bisa dibayar', ! $svc->bisaDibayar()->contains('id', $memo->id));
    $setujui($memo->approval_id, ['Accounting', 'Finance', 'GM', 'Direktur']);
    $cek('Direktur menyetujui → bisa dibayar', $svc->bisaDibayar()->contains('id', $memo->id));

    $sebagai($admin);
    $dasar = ['memo_internal_id' => $memo->id, 'kuitansi_date' => $tgl, 'cash_account_id' => $bank, 'advance_account_id' => $akunUm, 'amount' => 1_000_000, 'alat_transaksi' => 'transfer'];
    $cek('melebihi nominal memo → ditolak', $kode(fn () => $svc->bayar(['amount' => 1_000_001] + $dasar, $admin->id)) === 'MEMO_BAYAR_LEBIH');
    $cek('akun debit bukan uang muka → ditolak', $kode(fn () => $svc->bayar(['advance_account_id' => $akunBukanUm] + $dasar, $admin->id)) === 'MEMO_BAYAR_AKUN');

    $resp = app()->call([app(ApKuitansiController::class), 'storeMemo'], ['request' => $minta('POST', $dasar)]);
    $kui = BsApKuitansi::where('memo_internal_id', $memo->id)->first();
    $cek('bayar lewat layar → kuitansi aktif, supplier ikut memo', $kui && $kui->status === 'active' && (int) $kui->supplier_id === $supplier && $sama($kui->total, 1_000_000), $sesi('error'));
    $jl = BsJournalLine::where('journal_id', $kui?->journal_id)->get();
    $cek('jurnal Dr Uang Muka / Cr Bank', $sama($jl->where('account_id', $akunUm)->sum('debit'), 1_000_000) && $sama($jl->where('account_id', $bank)->sum('credit'), 1_000_000));
    $cek('sekali bayar — memo tidak bisa dibayar lagi', $kode(fn () => $svc->bayar($dasar, $admin->id)) === 'MEMO_BAYAR_TIDAK_BOLEH');

    echo "\n=== 2. Invoice wajib menyusul ===\n";
    $out = $svc->outstanding($pengaju->id);
    $cek('masuk daftar Memo Urgent Belum Invoice', $out->contains('memo_id', $memo->id));
    $cek('belum lewat batas → pengaju masih boleh ajukan memo', ! $svc->terblokir($pengaju));
    $kui->forceFill(['kuitansi_date' => now()->subDays($svc->batasHari() + 1)->toDateString()])->save();
    $cek('lewat batas hari → pengaju terblokir', $svc->terblokir($pengaju));
    $sebagai($pengaju);
    app()->call([app(ApprovalInboxController::class), 'store'], ['request' => $minta('POST', ['memo_date' => $tgl, 'supplier_id' => $supplier, 'subject' => 'x', 'body' => 'x', 'amount' => 1000])]);
    $cek('form Memo Urgent menolak pengaju terblokir', str_contains($sesi('error'), 'invoice hutangnya belum dibuat'));
    $cek('pengaju lain tidak terblokir', ! $svc->terblokir($admin));

    echo "\n=== 3. TTD cetakan ===\n";
    $ttd = $svc->kotakTtd($kui->fresh(['memo.approval.steps']));
    $cek('kotak TTD: Kasir · Direktur · MW / BA', array_column($ttd, 'title') === ['Kasir', 'Direktur', 'MW / BA'], json_encode(array_column($ttd, 'title')));
    $cek('Direktur tertanda, MW / BA belum', $ttd[1]['signed'] && ! $ttd[2]['signed']);
    $setujui($memo->approval_id, ['Owner']);
    $ttd = $svc->kotakTtd($kui->fresh(['memo.approval.steps']));
    $cek('MW / BA menyetujui memo → TTD-nya otomatis tampil', $ttd[2]['signed'] && $ttd[2]['name'] === $approver['Owner']->name);
    $sebagai($admin);
    $html = app()->call([app(ApKuitansiController::class), 'print'], ['request' => $minta('GET'), 'kuitansi' => $kui->fresh()])->getContent();
    $cek('cetakan memuat no. memo & kotak MW / BA', str_contains($html, $memo->memo_number) && str_contains($html, 'MW / BA'));

    echo "\n=== 4. Invoice penyusul & potong uang muka ===\n";
    $buatInvoice = fn (float $nilai, string $no) => BsApInvoice::create([
        'tenant_id' => $tenantId, 'invoice_number' => $no, 'invoice_date' => $tgl, 'due_date' => $tgl, 'supplier_id' => $supplier,
        'payable_account_id' => $hutang, 'expense_account_id' => $hutang, 'description' => 'Invoice uji memo', 'total' => $nilai, 'net_payable' => $nilai,
        'paid_amount' => 0, 'status' => 'open', 'memo_internal_id' => $memo->id, 'created_by' => $admin->id,
    ]);
    $kecil = $buatInvoice(800_000, 'UJI-MEMO-KECIL');
    $cek('invoice lebih kecil dari yang dibayar → ditolak', $kode(fn () => $svc->assertInvoiceCukup($kecil)) === 'MEMO_INVOICE_KURANG');
    $kecil->forceDelete();
    $inv = $buatInvoice(1_500_000, 'UJI-MEMO-OK');
    $svc->assertInvoiceCukup($inv);
    $cek('invoice ≥ yang dibayar → lolos; keluar dari daftar outstanding', ! $svc->outstanding()->contains('memo_id', $memo->id) && ! $svc->terblokir($pengaju));

    $kuiSvc = app(ApKuitansiService::class);
    $bayarInv = fn (float $nilai) => $kuiSvc->create(['kuitansi_date' => $tgl, 'supplier_id' => $supplier, 'cash_account_id' => $bank, 'alat_transaksi' => 'transfer',
        'lines' => [['ap_invoice_id' => $inv->id, 'amount' => $nilai]]], $admin->id);
    $cek('pelunasan di bawah uang muka → ditolak (potong wajib)', $kode(fn () => $bayarInv(500_000)) === 'KUI_UM_WAJIB');
    $kuiInv = $bayarInv(1_500_000);
    $baris = $kuiInv->lines()->first();
    $cek('baris kuitansi: potong uang muka 1 jt, kas 500 rb', $sama($baris->um_amount, 1_000_000) && (int) $baris->um_kuitansi_id === $kui->id && $sama($kuiInv->total, 500_000));
    $cek('uang muka memo habis terpakai (kuitansi menunggu dihitung)', $sama($svc->sisaUangMuka($kui), 0));
    $kuiSvc->approve($kuiInv, $admin->id);
    $jl = BsJournalLine::where('journal_id', $kuiInv->fresh()->journal_id)->get();
    $cek('jurnal Dr Hutang 1,5 jt / Cr Uang Muka 1 jt / Cr Bank 500 rb',
        $sama($jl->where('account_id', $hutang)->sum('debit'), 1_500_000) && $sama($jl->where('account_id', $akunUm)->sum('credit'), 1_000_000) && $sama($jl->where('account_id', $bank)->sum('credit'), 500_000),
        $jl->map(fn ($l) => "{$l->account_id}:{$l->debit}/{$l->credit}")->implode(' '));
    $cek('invoice lunas', $inv->fresh()->status === 'paid');
    $cek('kuitansi memo tidak bisa di-void setelah uang mukanya terpakai', $kode(fn () => $kuiSvc->void($kui->fresh())) === 'KUI_UM_TERPAKAI');
    $kuiSvc->void($kuiInv->fresh());
    $cek('void kuitansi invoice → uang muka tersedia lagi', $sama($svc->sisaUangMuka($kui), 1_000_000) && $inv->fresh()->status === 'open');

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
