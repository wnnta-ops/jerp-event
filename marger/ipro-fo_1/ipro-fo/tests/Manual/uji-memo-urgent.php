<?php

declare(strict_types=1);

/*
 * Uji form Memo Urgent (Approval Inbox › Memo Urgent).
 *
 * Yang dijaga:
 *   · Pilihan departemen = cost center karyawan user di Employee Center; SuperAdmin = semua.
 *   · User tanpa departemen tidak bisa mengajukan.
 *   · Departemen milik orang lain ditolak; nominal wajib (> 0).
 *   · Yang sah langsung tersimpan + masuk rantai approval memo_internal (nama departemen disimpan).
 *
 * Data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-memo-urgent.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Http\Controllers\Web\ApprovalInbox\ApprovalInboxController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    $buatUser = function (string $nama, array $roles) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama, 'username' => 'u'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $cc = DB::table('bs_cost_centers')->where('is_active', 1)->orderBy('id')->get(['id', 'name']);
    $staf = $buatUser('Staf Memo Urgent Uji', ['Collection']);
    $nik = 'UJI-'.Str::upper(Str::random(8));
    DB::table('bs_employees')->insert(['tenant_id' => $tenantId, 'nik' => $nik, 'name' => $staf->name, 'is_active' => true,
        'cost_center_id' => $cc[0]->id, 'created_at' => now(), 'updated_at' => now()]);
    $staf->forceFill(['employee_nip' => $nik])->save();
    $tanpa = $buatUser('Staf Tanpa Dept Uji', ['Collection']);
    $admin = $buatUser('Admin Memo Uji', ['SuperAdmin']);

    $ctl = app(ApprovalInboxController::class);
    $pilihan = new ReflectionMethod($ctl, 'pilihanDepartemen');
    $minta = function (User $u, array $isi = []) {
        auth()->setUser($u);
        $r = Request::create('/uji', 'POST', $isi, [], [], ['HTTP_REFERER' => url('/approval-inbox/ajukan')]);
        $r->setLaravelSession(app('session.store'));
        $r->setUserResolver(fn () => $u);
        app()->instance('request', $r);

        return $r;
    };
    $galat = function (callable $fn): array {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
    $isi = fn (array $x = []) => $x + ['memo_date' => now()->toDateString(), 'subject' => 'Uji memo urgent', 'body' => 'Isi uji', 'amount' => 2500000];

    echo "\n=== 1. Pilihan departemen ===\n";
    $p = $pilihan->invoke($ctl, $staf);
    $cek('staf → hanya departemennya sendiri', count($p) === 1 && $p[0]['id'] === (int) $cc[0]->id, json_encode($p));
    $cek('SuperAdmin → semua departemen aktif', count($pilihan->invoke($ctl, $admin)) === $cc->count());
    $cek('tanpa karyawan/cost center → kosong', $pilihan->invoke($ctl, $tanpa) === []);

    echo "\n=== 2. Validasi ===\n";
    $ctl->store($minta($tanpa, $isi(['cost_center_id' => $cc[0]->id])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class));
    $cek('tanpa departemen → ditolak dengan pesan', str_contains((string) app('session.store')->pull('error'), 'Departemen Anda belum diatur'));
    if ($cc->count() > 1) {
        $g = $galat(fn () => $ctl->store($minta($staf, $isi(['cost_center_id' => $cc[1]->id])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class)));
        $cek('departemen orang lain → ditolak', isset($g['cost_center_id']), json_encode($g));
    }
    $g = $galat(fn () => $ctl->store($minta($staf, $isi(['cost_center_id' => $cc[0]->id, 'amount' => null])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class)));
    $cek('nominal kosong → wajib', isset($g['amount']), json_encode($g));
    $g = $galat(fn () => $ctl->store($minta($staf, $isi(['cost_center_id' => $cc[0]->id, 'amount' => 0])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class)));
    $cek('nominal 0 → wajib', isset($g['amount']), json_encode($g));

    echo "\n=== 3. Diajukan → langsung masuk approval ===\n";
    $res = $ctl->store($minta($staf, $isi(['cost_center_id' => $cc[0]->id])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class));
    $memo = MemoInternal::query()->where('created_by', $staf->id)->latest('id')->first();
    $cek('memo tersimpan dengan nama departemen', $memo && $memo->department === $cc[0]->name, (string) $memo?->department);
    $cek('nominal tersimpan', $memo && abs((float) $memo->amount - 2500000) < 0.01);
    $cek('langsung masuk rantai approval', $memo && $memo->approval_id && DB::table('approvals')->where('id', $memo->approval_id)->value('status') === 'pending');
    $cek('diarahkan ke tracking approval', str_contains($res->getTargetUrl(), '/approval-inbox/'.$memo?->approval_id), $res->getTargetUrl());
    $ctl->store($minta($admin, $isi(['cost_center_id' => $cc->last()->id])), app(\App\Domain\ApprovalInbox\Actions\SubmitMemoInternal::class));
    $cek('SuperAdmin boleh departemen mana pun', MemoInternal::query()->where('created_by', $admin->id)->value('department') === $cc->last()->name);

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
