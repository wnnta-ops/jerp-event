<?php

declare(strict_types=1);

/*
 * Uji TTD (tanda tangan) PNG per user — Security → Username (User) → Edit.
 *
 * Yang dijaga:
 *   · Hanya PNG yang diterima; file lama terhapus saat TTD diganti, dan saat dihapus.
 *   · File di disk PRIVAT, diambil lewat route users.signature (bukan URL publik).
 *   · Setelah user menyetujui satu tahap Approval Inbox, TTD-nya ikut di tracker tahap itu
 *     — dan tidak muncul di tahap yang belum ia setujui.
 *
 * Disk 'local' dipalsukan (Storage::fake) dan data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-ttd-user.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Http\Controllers\Web\Settings\UserSignatureController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

Storage::fake('local');
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
    $admin = $buatUser('Admin TTD Uji', 'SuperAdmin');
    $acc = $buatUser('Accounting TTD Uji', 'Accounting');
    auth()->setUser($admin);

    $ctrl = new UserSignatureController;
    $unggah = function (User $u, UploadedFile $file) use ($ctrl, $admin) {
        $req = Request::create('/', 'POST', [], [], ['signature' => $file]);
        $req->setUserResolver(fn () => $admin);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        return $ctrl->store($req, $u);
    };

    echo "\n=== 1. Unggah & ganti ===\n";
    $unggah($acc, UploadedFile::fake()->image('ttd.png', 400, 150));
    $acc->refresh();
    $pertama = $acc->signature_path;
    $cek('signature_path terisi', (bool) $pertama);
    $cek('file ada di disk privat', Storage::disk('local')->exists((string) $pertama));
    $cek('URL lewat route users.signature', str_contains((string) $acc->signatureUrl(), "/users/{$acc->id}/signature"));
    $cek('path TTD tidak ikut ke JSON user', ! array_key_exists('signature_path', $acc->toArray()));

    sleep(1); // nama file berstempel detik
    $unggah($acc, UploadedFile::fake()->image('ttd-baru.png', 300, 120));
    $acc->refresh();
    $cek('TTD diganti ke file baru', $acc->signature_path !== $pertama);
    $cek('file lama terhapus', ! Storage::disk('local')->exists((string) $pertama));

    echo "\n=== 2. Validasi ===\n";
    $ditolak = false;
    try {
        $unggah($acc, UploadedFile::fake()->image('ttd.jpg', 300, 120));
    } catch (ValidationException) {
        $ditolak = true;
    }
    $cek('JPG ditolak', $ditolak);
    $cek('TTD lama tetap utuh setelah unggahan ditolak', Storage::disk('local')->exists((string) $acc->fresh()->signature_path));

    echo "\n=== 3. Stempel di tracker Approval Inbox ===\n";
    $staf = $buatUser('Staf TTD Uji', 'Collection');
    auth()->setUser($staf);
    $memo = app(SubmitMemoInternal::class)->execute([
        'memo_date' => now()->toDateString(), 'department' => 'Uji',
        'subject' => 'Uji TTD', 'body' => 'Isi', 'amount' => null,
    ]);
    $inbox = app(ApprovalInboxQuery::class);
    $muat = fn () => \App\Domain\Approval\Models\Approval::with(['steps', 'approvable'])->findOrFail($memo->approval_id);
    $cek('sebelum disetujui: tahap Accounting tanpa TTD', $inbox->stages($muat())[1]['signature'] === null);
    auth()->setUser($acc->fresh());
    app(ActOnApproval::class)->execute($muat(), 'approve');
    $st = $inbox->stages($muat());
    $cek('setelah disetujui: TTD Accounting tampil', $st[1]['signature'] === $acc->fresh()->signatureUrl());
    $cek('tahap Finance (belum) tanpa TTD', $st[2]['signature'] === null);
    $cek('pengaju tanpa TTD → null (bukan error)', $st[0]['signature'] === null);

    echo "\n=== 4. Hapus ===\n";
    auth()->setUser($admin);
    $path = $acc->fresh()->signature_path;
    $req = Request::create('/', 'DELETE');
    $req->setUserResolver(fn () => $admin);
    $req->setLaravelSession(app('session.store'));
    app()->instance('request', $req);
    $ctrl->destroy($req, $acc->fresh());
    $cek('signature_path kosong', $acc->fresh()->signature_path === null);
    $cek('file terhapus dari disk', ! Storage::disk('local')->exists((string) $path));
    $cek('signatureUrl null', $acc->fresh()->signatureUrl() === null);

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
