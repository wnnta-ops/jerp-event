<?php

declare(strict_types=1);

/*
 * Uji Approval Inbox — Memo Internal di rantai 6 tahap
 *   Dept Head (pengaju) → Accounting → Finance → GM → Direktur → MW / BA (Owner).
 *
 * Yang dijaga:
 *   · Staf hanya melihat memo yang IA ajukan; staf lain tidak bisa membuka detailnya.
 *   · "Perlu Persetujuan" hanya memuat dokumen yang tahap aktifnya giliran role saya —
 *     Finance tidak melihat (dan tidak bisa menyetujui) memo yang masih di Accounting.
 *   · Setuju penuh → memo 'disetujui'; ditolak di GM → memo 'ditolak', tahap sesudahnya
 *     'skipped', alasan tercatat di tracker.
 *
 * Butuh seeder ApprovalInboxSeeder sudah dijalankan. Data uji DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-approval-inbox-memo.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
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

DB::beginTransaction();

try {
    $buatUser = function (string $nama, ?string $role) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama,
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        if ($role) {
            $u->assignRole($role);
        }

        return $u->fresh();
    };
    $staf = $buatUser('Staf Uji', 'Finance');      // pengaju (perannya bebas)
    $stafLain = $buatUser('Staf Lain Uji', 'Collection');
    $role = [];
    foreach (['Accounting', 'Finance', 'GM', 'Direktur', 'Owner'] as $r) {
        $role[$r] = $buatUser("Approver {$r} Uji", $r);
    }
    $inbox = app(ApprovalInboxQuery::class);
    $act = app(ActOnApproval::class);
    // Chain memo per user (System › Approval Chain) diarahkan ke approver uji — ikut dibatalkan bersama transaksi.
    $arahkanChainMemo = function (array $labelKeUser): void {
        $chains = DB::table('approval_chains')->where('document_type', 'memo_internal')->pluck('id');
        foreach ($labelKeUser as $label => $u) {
            DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chains)->where('label', $label)
                ->update(['approver_type' => 'user', 'approver_value' => (string) $u->id]);
        }
    };
    $arahkanChainMemo(['Accounting' => $role['Accounting'], 'Finance' => $role['Finance'], 'GM' => $role['GM'], 'Direktur' => $role['Direktur'], 'MW / BA' => $role['Owner']]);
    $sebagai = function (User $u) {
        auth()->setUser($u);
    };
    $muat = fn (int $id) => App\Domain\Approval\Models\Approval::with(['steps', 'approvable'])->findOrFail($id);
    $state = fn ($a) => implode(',', array_column($inbox->stages($a), 'state'));

    echo "\n=== 1. Pengajuan ===\n";
    $sebagai($staf);
    $memo = app(SubmitMemoInternal::class)->execute([
        'jenis' => 'reguler', 'memo_date' => now()->toDateString(), 'department' => 'Marketing',
        'subject' => 'Uji pengadaan banner', 'body' => 'Isi uji', 'amount' => 2500000,
    ]);
    $cek('nomor memo format MI/YYMM/NNNNN', (bool) preg_match('#^MI/\d{4}/\d{5}$#', $memo->memo_number), $memo->memo_number);
    $cek('status memo diajukan', $memo->status === 'diajukan');
    $a = $muat($memo->approval_id);
    $cek('5 langkah approval setelah pengaju', $a->steps->count() === 5);
    $cek('tracker 6 tahap, Accounting aktif', $state($a) === 'done,current,waiting,waiting,waiting,waiting', $state($a));

    echo "\n=== 2. Siapa melihat apa ===\n";
    $cek('pengaju melihat di Pengajuan Saya', $inbox->mine($staf)->contains('id', $a->id));
    $cek('staf lain TIDAK melihat di Pengajuan Saya', ! $inbox->mine($stafLain)->contains('id', $a->id));
    $cek('staf lain TIDAK bisa buka detail', ! $inbox->canView($stafLain, $a));
    $cek('Accounting melihat di Perlu Persetujuan', $inbox->needsMe($role['Accounting'])->contains('id', $a->id));
    $cek('Finance BELUM melihat (bukan gilirannya)', ! $inbox->needsMe($role['Finance'])->contains('id', $a->id));

    $ditolak = false;
    try {
        $sebagai($role['Finance']);
        $act->execute($a, 'approve');
    } catch (BusinessRuleException) {
        $ditolak = true;
    }
    $cek('Finance tidak bisa menyetujui tahap Accounting', $ditolak);

    echo "\n=== 3. Jalan penuh sampai disetujui ===\n";
    foreach (['Accounting', 'Finance', 'GM', 'Direktur'] as $r) {
        $sebagai($role[$r]);
        $act->execute($muat($a->id), 'approve', "ok {$r}");
    }
    $a = $muat($a->id);
    $cek('setelah Direktur: MW / BA aktif', $state($a) === 'done,done,done,done,done,current', $state($a));
    $cek('Owner melihat di Perlu Persetujuan', $inbox->needsMe($role['Owner'])->contains('id', $a->id));
    $sebagai($role['Owner']);
    $act->execute($a, 'approve');
    $a = $muat($a->id);
    $cek('approval approved', $a->status === 'approved');
    $cek('memo disetujui (listener)', MemoInternal::find($memo->id)->status === 'disetujui');
    $cek('semua tahap done', $state($a) === 'done,done,done,done,done,done', $state($a));
    $cek('GM melihat di Riwayat', $inbox->history($role['GM'])->contains('id', $a->id));

    echo "\n=== 4. Ditolak di GM ===\n";
    $sebagai($staf);
    $memo2 = app(SubmitMemoInternal::class)->execute([
        'jenis' => 'reguler', 'memo_date' => now()->toDateString(), 'department' => 'Marketing',
        'subject' => 'Uji ditolak', 'body' => 'Isi uji', 'amount' => null,
    ]);
    foreach (['Accounting', 'Finance'] as $r) {
        $sebagai($role[$r]);
        $act->execute($muat($memo2->approval_id), 'approve');
    }
    $sebagai($role['GM']);
    $act->execute($muat($memo2->approval_id), 'reject', 'Anggaran tidak tersedia');
    $b = $muat($memo2->approval_id);
    $cek('approval rejected', $b->status === 'rejected');
    $cek('memo ditolak (listener)', MemoInternal::find($memo2->id)->status === 'ditolak');
    $cek('tracker: GM rejected, sisanya skipped', $state($b) === 'done,done,done,rejected,skipped,skipped', $state($b));
    $cek('alasan tolak tampil di tracker', ($inbox->stages($b)[3]['notes'] ?? null) === 'Anggaran tidak tersedia');
    $cek('Direktur tidak mendapat dokumen yang sudah ditolak', ! $inbox->needsMe($role['Direktur'])->contains('id', $b->id));

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
