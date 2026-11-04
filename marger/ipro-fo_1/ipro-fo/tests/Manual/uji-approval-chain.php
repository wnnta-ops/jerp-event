<?php

declare(strict_types=1);

/*
 * Uji System › Approval Chain (per user) — jalankan: php tests/Manual/uji-approval-chain.php
 *
 *  1. Memo Urgent → chain MI-URGENT (4 level, tanpa MW / BA); Memo Reguler → MI-REGULER (5 level).
 *  2. Level per USER: hanya user yang ditunjuk yang bisa menyetujui (peran sama tidak cukup).
 *  3. Snapshot: mengubah / menambah / menghapus level hanya berlaku untuk pengajuan berikutnya.
 *  4. Layar: tambah, edit, hapus level → level dinomori ulang tanpa celah, terakhir = final.
 *
 * Semua dalam transaksi yang dibatalkan di akhir.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\Settings\ApprovalChainController;
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

DB::beginTransaction();

try {
    $buatUser = function (string $nama, string $role) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama,
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u->fresh();
    };
    $admin = User::where('email', '1234@jerp.test')->firstOrFail();
    $pengaju = $buatUser('Pengaju Chain Uji', 'Collection');
    $u = [];
    foreach (['Accounting', 'Finance', 'GM', 'Direktur', 'Owner'] as $r) {
        $u[$r] = $buatUser("{$r} Chain Uji", $r);
    }
    $accLain = $buatUser('Accounting Lain Uji', 'Accounting');
    $label = ['Accounting' => 'Accounting', 'Finance' => 'Finance', 'GM' => 'GM', 'Direktur' => 'Direktur', 'Owner' => 'MW / BA'];
    $chains = ApprovalChain::where('document_type', 'memo_internal')->pluck('id', 'code');
    foreach ($label as $r => $l) {
        DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chains->values())->where('label', $l)
            ->update(['approver_type' => 'user', 'approver_value' => (string) $u[$r]->id]);
    }
    $sebagai = fn (User $x) => auth()->setUser($x);
    $act = app(ActOnApproval::class);
    $inbox = app(ApprovalInboxQuery::class);
    $muat = fn (int $id) => Approval::with(['steps', 'approvable'])->findOrFail($id);
    $ajukan = fn (string $jenis) => app(SubmitMemoInternal::class)->execute(['jenis' => $jenis, 'memo_date' => now()->toDateString(),
        'department' => 'Uji', 'subject' => "Uji chain {$jenis}", 'body' => 'Isi', 'amount' => 1_000_000]);
    $label2 = fn (Approval $a) => implode(' → ', array_column($inbox->stages($a), 'label'));

    echo "=== 1. Chain per jenis memo ===\n";
    $cek('chain MI-DEFAULT (per peran) nonaktif', ! ApprovalChain::where('code', 'MI-DEFAULT')->value('is_active'));
    $sebagai($pengaju);
    $urgent = $muat($ajukan('urgent')->approval_id);
    $reguler = $muat($ajukan('reguler')->approval_id);
    $cek('Memo Urgent → MI-URGENT, 4 level tanpa MW / BA', (int) $urgent->approval_chain_id === (int) $chains['MI-URGENT'] && $urgent->steps->count() === 4, $label2($urgent));
    $cek('Memo Reguler → MI-REGULER, 5 level', (int) $reguler->approval_chain_id === (int) $chains['MI-REGULER'] && $reguler->steps->count() === 5);
    $cek('tracker urgent: Dept. Head → … → Direktur', $label2($urgent) === 'Dept. Head → Accounting → Finance → GM → Direktur', $label2($urgent));
    $cek('tracker reguler diakhiri MW / BA', str_ends_with($label2($reguler), 'Direktur → MW / BA'), $label2($reguler));
    $cek('snapshot tersimpan di approval_steps', $urgent->steps->every(fn ($s) => $s->approver_type === 'user' && $s->label));

    echo "\n=== 2. Approver per user ===\n";
    $cek('Accounting yang ditunjuk melihat di Perlu Persetujuan', $inbox->needsMe($u['Accounting'])->contains('id', $urgent->id));
    $cek('Accounting lain (peran sama) TIDAK melihat', ! $inbox->needsMe($accLain)->contains('id', $urgent->id));
    $sebagai($accLain);
    $ditolak = false;
    try {
        $act->execute($urgent, 'approve');
    } catch (BusinessRuleException $e) {
        $ditolak = $e->errorCode() === 'APPROVAL_FORBIDDEN';
    }
    $cek('Accounting lain tidak bisa menyetujui', $ditolak);
    foreach (['Accounting', 'Finance', 'GM', 'Direktur'] as $r) {
        $sebagai($u[$r]);
        $act->execute($muat($urgent->id), 'approve');
    }
    $cek('Memo Urgent disetujui penuh setelah Direktur', $muat($urgent->id)->status === 'approved');

    echo "\n=== 3. Perubahan chain hanya untuk pengajuan berikutnya ===\n";
    $ctl = app(ApprovalChainController::class);
    $minta = function (string $method, array $isi = []) {
        $r = Illuminate\Http\Request::create('/', $method, $isi);
        $r->setUserResolver(fn () => auth()->user());
        $r->setLaravelSession(app('session.store'));
        app()->instance('request', $r);

        return $r;
    };
    $sebagai($admin);
    $stepAcc = DB::table('approval_chain_steps')->where('approval_chain_id', $chains['MI-REGULER'])->where('level', 1)->first();
    app()->call([$ctl, 'updateStep'], ['request' => $minta('PUT', ['user_id' => $accLain->id, 'label' => 'Accounting']), 'step' => App\Domain\Approval\Models\ApprovalChainStep::find($stepAcc->id)]);
    $stepFin = DB::table('approval_chain_steps')->where('approval_chain_id', $chains['MI-REGULER'])->where('level', 2)->first();
    app()->call([$ctl, 'destroyStep'], ['request' => $minta('DELETE'), 'step' => App\Domain\Approval\Models\ApprovalChainStep::find($stepFin->id)]);
    $levels = DB::table('approval_chain_steps')->where('approval_chain_id', $chains['MI-REGULER'])->orderBy('level')->get();
    $cek('hapus level → dinomori ulang 1..4, terakhir final', $levels->pluck('level')->all() === [1, 2, 3, 4] && (bool) $levels->last()->is_final && ! $levels->first()->is_final);
    app()->call([$ctl, 'storeStep'], ['request' => $minta('POST', ['user_id' => $u['Finance']->id, 'label' => 'Komisaris']), 'chain' => ApprovalChain::find($chains['MI-REGULER'])]);
    $levels = DB::table('approval_chain_steps')->where('approval_chain_id', $chains['MI-REGULER'])->orderBy('level')->get();
    $cek('tambah level → jadi level 5 & final', $levels->count() === 5 && $levels->last()->label === 'Komisaris' && (bool) $levels->last()->is_final && $levels->where('is_final', 1)->count() === 1);

    $lama = $muat($reguler->id);
    $cek('memo reguler LAMA tetap 5 level & alur lama', $lama->steps->count() === 5 && $label2($lama) === 'Dept. Head → Accounting → Finance → GM → Direktur → MW / BA', $label2($lama));
    $cek('memo lama: Accounting lama tetap yang berhak', $inbox->needsMe($u['Accounting'])->contains('id', $lama->id) && ! $inbox->needsMe($accLain)->contains('id', $lama->id));
    $sebagai($pengaju);
    $baru = $muat($ajukan('reguler')->approval_id);
    $cek('memo reguler BARU memakai alur baru', $label2($baru) === 'Dept. Head → Accounting → GM → Direktur → MW / BA → Komisaris', $label2($baru));
    $cek('memo baru: Accounting baru yang berhak', $inbox->needsMe($accLain)->contains('id', $baru->id) && ! $inbox->needsMe($u['Accounting'])->contains('id', $baru->id));

} catch (Throwable $e) {
    $gagal++;
    echo "GALAT: {$e->getMessage()}\n  di {$e->getFile()}:{$e->getLine()}\n";
} finally {
    DB::rollBack();
}

echo "\n".($gagal === 0 ? "SEMUA LULUS ({$lulus})" : "GAGAL: {$gagal}, lulus: {$lulus}")."\n";
