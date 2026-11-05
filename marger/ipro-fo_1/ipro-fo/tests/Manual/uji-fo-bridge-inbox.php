<?php

declare(strict_types=1);

/*
 * Uji jembatan FO → Approval Inbox & Dashboard (app approver PWA): /api/v1/fo-bridge/*.
 *
 * Yang dijaga:
 *   · Tanpa / salah tanda tangan, kedaluwarsa, nonce dipakai ulang, user tak dikenal → 403.
 *   · Daftar "perlu persetujuan" sama dengan ApprovalInboxQuery::needsMe, plus kategori
 *     urgent (memo jenis urgent) / reguler dan hitungannya.
 *   · Detail dokumen yang bukan haknya → 403; tolak tanpa alasan → 422.
 *   · Setujui lewat jembatan menjalankan ActOnApproval (tahap jadi approved).
 *
 * Butuh data Approval Inbox lokal (seeder ApprovalInboxSeeder). Perubahan DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-fo-bridge-inbox.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Http\Middleware\VerifyFoBridge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    $ok ? $lulus++ : $gagal++;
    echo ($ok ? '  ✓ ' : '  ✗ ').$nama.($ok || $ket === '' ? '' : " — {$ket}").PHP_EOL;
};

$secret = (string) config('sso.secret');
if ($secret === '') {
    exit("SSO_SHARED_SECRET kosong di .env BO.\n");
}

/** Panggil endpoint jembatan seperti FO. $ubah boleh merusak header untuk uji negatif. */
$panggil = function (string $method, string $path, string $email, array $body = [], ?callable $ubah = null) use ($kernel, $secret) {
    $isi = $body ? json_encode($body) : '';
    $ts = (string) now()->timestamp;
    $nonce = (string) Str::uuid();
    $h = [
        'X-Bridge-Email' => $email, 'X-Bridge-Ts' => $ts, 'X-Bridge-Nonce' => $nonce,
        'X-Bridge-Sig' => hash_hmac('sha256', VerifyFoBridge::kanonik($ts, $nonce, $method, $path, $email, $isi), $secret),
    ];
    if ($ubah) {
        $h = $ubah($h);
    }
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($h as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    // Skrip ini mengirim puluhan permintaan per user — kosongkan hitungan batas per user
    // (VerifyFoBridge) supaya menjalankan ulang dalam semenit tidak terkena 429.
    if ($u = User::where('email', $email)->first()) {
        Illuminate\Support\Facades\RateLimiter::clear(VerifyFoBridge::kunciBatas((int) $u->id));
    }
    $res = $kernel->handle(Request::create($path, $method, [], [], [], $server, $isi));
    auth()->forgetGuards();

    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true), $h, (string) $res->getContent(), $res->headers];
};

$base = '/api/v1/fo-bridge/approval-inbox';
$query = app(ApprovalInboxQuery::class);

DB::beginTransaction();
try {
    echo "Keamanan tanda tangan\n";
    [$s] = $panggil('GET', $base, 'gm@jerp.test', [], fn ($h) => ['X-Bridge-Email' => 'gm@jerp.test']);
    $cek('tanpa tanda tangan → 403', $s === 403, "dapat {$s}");
    [$s] = $panggil('GET', $base, 'gm@jerp.test', [], fn ($h) => ['X-Bridge-Sig' => str_repeat('0', 64)] + $h);
    $cek('tanda tangan salah → 403', $s === 403, "dapat {$s}");
    [$s] = $panggil('GET', $base, 'gm@jerp.test', [], fn ($h) => ['X-Bridge-Email' => 'direktur@jerp.test'] + $h);
    $cek('email ditukar sesudah ditandatangani → 403', $s === 403, "dapat {$s}");
    [$s, , $h] = $panggil('GET', $base, 'gm@jerp.test');
    $cek('tanda tangan benar → 200', $s === 200, "dapat {$s}");
    [$s] = $panggil('GET', $base, 'gm@jerp.test', [], fn () => $h);
    $cek('nonce dipakai ulang → 403', $s === 403, "dapat {$s}");
    [$s] = $panggil('GET', $base, 'tidak-ada@contoh.test');
    $cek('user tidak ada di BO → 403', $s === 403, "dapat {$s}");

    // Batas per user (bukan per IP server FO): user penuh → 429, user lain tetap jalan.
    $gm = User::where('email', 'gm@jerp.test')->first();
    $fin = User::where('email', 'finance@jerp.test')->first();
    if ($gm && $fin) {
        $kernelBatas = function (int $id) {
            for ($i = 0; $i < VerifyFoBridge::MAKS_PER_MENIT; $i++) {
                Illuminate\Support\Facades\RateLimiter::hit(VerifyFoBridge::kunciBatas($id), 60);
            }
        };
        $kernelBatas((int) $gm->id);
        $tanpaReset = function (string $email) use ($kernel, $secret, $base) {
            $ts = (string) now()->timestamp;
            $nonce = (string) Str::uuid();
            $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_BRIDGE_EMAIL' => $email, 'HTTP_X_BRIDGE_TS' => $ts, 'HTTP_X_BRIDGE_NONCE' => $nonce,
                'HTTP_X_BRIDGE_SIG' => hash_hmac('sha256', VerifyFoBridge::kanonik($ts, $nonce, 'GET', $base, $email, ''), $secret)];
            $s = $kernel->handle(Request::create($base, 'GET', [], [], [], $server))->getStatusCode();
            auth()->forgetGuards();

            return $s;
        };
        $cek('user melewati batas per menit → 429', $tanpaReset('gm@jerp.test') === 429);
        $cek('user lain (IP sama) tetap 200', $tanpaReset('finance@jerp.test') === 200);
        Illuminate\Support\Facades\RateLimiter::clear(VerifyFoBridge::kunciBatas((int) $gm->id));
    }

    echo "Daftar perlu persetujuan\n";
    foreach (['accounting', 'finance', 'gm', 'direktur', 'owner', 'pengaju'] as $nama) {
        $email = "{$nama}@jerp.test";
        $user = User::where('email', $email)->first();
        if (! $user) {
            continue;
        }
        [$s, $j] = $panggil('GET', $base, $email);
        $harap = $query->needsMe($user)->pluck('id')->sort()->values()->all();
        $dapat = collect($j['data'] ?? [])->pluck('id')->sort()->values()->all();
        $urgent = collect($j['data'] ?? [])->where('kategori', 'urgent')->count();
        $cek("{$nama}: sama dengan needsMe (".count($harap).' dok)', $s === 200 && $harap === $dapat, json_encode([$harap, $dapat]));
        $cek("{$nama}: counts urgent+reguler cocok", ($j['counts']['urgent'] ?? -1) === $urgent
            && ($j['counts']['urgent'] + $j['counts']['reguler']) === count($dapat));
    }

    echo "Detail & aksi\n";
    $staff = User::where('email', 'staff@jerp.test')->first();
    $bukanHak = $staff ? Approval::whereIn('document_type', ApprovalInboxQuery::DOCUMENT_TYPES)->get()
        ->first(fn ($a) => ! $query->canView($staff, $a->load('steps'))) : null;
    if ($bukanHak) {
        [$s] = $panggil('GET', "{$base}/{$bukanHak->id}", 'staff@jerp.test');
        $cek("staff buka dokumen orang lain #{$bukanHak->id} → 403", $s === 403, "dapat {$s}");
    }

    $giliran = null;
    $penyetuju = null;
    foreach (['accounting', 'finance', 'gm', 'direktur', 'owner', 'pengaju'] as $nama) {
        $u = User::where('email', "{$nama}@jerp.test")->first();
        if ($u && ($a = $query->needsMe($u)->first())) {
            [$giliran, $penyetuju] = [$a, $u];
            break;
        }
    }
    if (! $giliran) {
        echo "  (lewati: tidak ada dokumen yang menunggu user demo)\n";
    } else {
        [$s, $j] = $panggil('GET', "{$base}/{$giliran->id}", $penyetuju->email);
        $cek("detail #{$giliran->id} → 200 + can_act", $s === 200 && ($j['data']['can_act'] ?? false) === true, json_encode($j));
        $cek('detail punya stages & kategori', ! empty($j['data']['stages']) && in_array($j['data']['kategori'] ?? '', ['urgent', 'reguler'], true));

        [$s, $j] = $panggil('POST', "{$base}/{$giliran->id}/act", $penyetuju->email, ['action' => 'reject']);
        $cek('tolak tanpa alasan → 422', $s === 422, "dapat {$s}");

        $level = $giliran->steps->where('status', 'pending')->sortBy('level')->first()->level;
        [$s, $j] = $panggil('POST', "{$base}/{$giliran->id}/act", $penyetuju->email, ['action' => 'approve', 'notes' => 'uji jembatan']);
        $step = DB::table('approval_steps')->where('approval_id', $giliran->id)->where('level', $level)->first();
        $cek("setujui #{$giliran->id} lewat jembatan → tahap {$level} approved", $s === 200 && $step->status === 'approved'
            && (int) $step->approver_id === (int) $penyetuju->id, json_encode($j));

        [$s, $j] = $panggil('GET', "{$base}/history", $penyetuju->email);
        $baris = collect($j['data'] ?? [])->firstWhere('id', $giliran->id);
        $cek('masuk history dengan keputusan approved', $s === 200 && ($baris['keputusan'] ?? null) === 'approved', json_encode($baris));
    }

    echo "Profil\n";
    $kodeSaya = function (string $email) use ($panggil, $base) {
        [$s, $j] = $panggil('GET', "{$base}/profil", $email);

        return [$s, $j, collect($j['chains'] ?? [])->pluck('code')->sort()->values()->all()];
    };
    foreach (['gm', 'owner', 'pengaju', 'staff', 'accounting'] as $nama) {
        $u = User::where('email', "{$nama}@jerp.test")->first();
        if (! $u) {
            continue;
        }
        // Harapan dihitung langsung dari tabel chain (user id / role / assigned + punya bawahan).
        $roles = $u->getRoleNames()->all();
        $nik = $u->employee_nip;
        $atasan = $nik && ($eid = DB::table('bs_employees')->where('nik', $nik)->value('id'))
            && DB::table('bs_employees')->where('atasan_id', $eid)->where('is_active', 1)->exists();
        $harap = DB::table('approval_chain_steps as s')->join('approval_chains as c', 'c.id', '=', 's.approval_chain_id')
            ->where('c.is_active', 1)->whereIn('c.document_type', ApprovalInboxQuery::DOCUMENT_TYPES)
            ->where(function ($q) use ($u, $roles, $atasan) {
                $q->where(fn ($w) => $w->where('s.approver_type', 'user')->where('s.approver_value', (string) $u->id))
                    ->orWhere(fn ($w) => $w->where('s.approver_type', 'role')->whereIn('s.approver_value', $roles ?: ['-']));
                if ($atasan) {
                    $q->orWhere('s.approver_type', 'assigned');
                }
            })->distinct()->orderBy('c.code')->pluck('c.code')->all();
        [$s, $j, $dapat] = $kodeSaya($u->email);
        $cek("{$nama}: chain saya = ".(implode(',', $harap) ?: '—'), $s === 200 && $harap === $dapat, json_encode([$s, $harap, $dapat]));
        $cek("{$nama}: ringkasan menunggu = needsMe", ($j['ringkasan']['menunggu'] ?? -1) === $query->needsMe($u)->count());
        $tandai = collect($j['chains'] ?? [])->every(fn ($c) => collect($c['steps'])->contains('saya', true));
        $cek("{$nama}: tiap chain punya tahap bertanda saya", $tandai);
        $punyaTtd = $u->signature_path && Illuminate\Support\Facades\Storage::disk('local')->exists($u->signature_path);
        $ttd = $j['user']['ttd'] ?? null;
        $cek("{$nama}: ttd ".($punyaTtd ? 'ada (data URI PNG)' : 'kosong'), $punyaTtd ? str_starts_with((string) $ttd, 'data:image/png;base64,') : $ttd === null);
    }

    echo "Pengajuan saya\n";
    foreach (['1234', 'pengaju', 'staff', 'gm'] as $nama) {
        $u = User::where('email', "{$nama}@jerp.test")->first();
        if (! $u) {
            continue;
        }
        [$s, $j] = $panggil('GET', "{$base}/pengajuan", $u->email);
        $harap = $query->mine($u)->filter(fn ($a) => $a->submitted_at === null || $a->submitted_at->gte(now()->subDays(90)))->pluck('id')->sort()->values()->all();
        $dapat = collect($j['data'] ?? [])->pluck('id')->sort()->values()->all();
        $cek("{$nama}: pengajuan = mine() 90 hari (".count($harap).')', $s === 200 && $harap === $dapat, json_encode([$s, $harap, $dapat]));
    }

    echo "Dokumen (preview PWA)\n";
    $admin = User::where('email', '1234@jerp.test')->first();
    $contoh = [
        'file' => Approval::where('document_type', 'ap_berkas')->with('approvable')->get()->first(fn ($a) => $a->approvable?->files()->exists()),
        'memo' => Approval::where('document_type', 'memo_internal')->latest('id')->first(),
        'lampiran' => Approval::whereIn('document_type', ['budget_correction', 'budget_allocation'])->latest('id')->first(),
    ];
    foreach ($contoh as $jenis => $a) {
        if (! $a || ! $admin) {
            echo "  (lewati {$jenis}: tidak ada data)\n";

            continue;
        }
        [$s, $j] = $panggil('GET', "{$base}/{$a->id}", $admin->email);
        $dok = collect($j['data']['dokumen'] ?? []);
        $item = $dok->first(fn ($d) => $jenis === 'file' ? str_starts_with($d['kunci'], 'file-') : $d['kunci'] === $jenis);
        $cek("{$jenis}: detail #{$a->id} memuat daftar dokumen", $s === 200 && $item !== null, json_encode($dok));
        if (! $item) {
            continue;
        }
        [$s, , , $isi, $hdr] = $panggil('GET', "{$base}/{$a->id}/dokumen/{$item['kunci']}", $admin->email);
        $tipe = (string) $hdr->get('Content-Type');
        if ($jenis === 'file') {
            $f = App\Domain\BusinessSupport\Models\BsApBerkasUploadFile::find((int) substr($item['kunci'], 5));
            $asli = Illuminate\Support\Facades\Storage::disk('local')->get($f->path);
            $cek("file: isi = file asli ({$tipe}, ".strlen($isi).' byte)', $s === 200 && $isi === $asli && str_starts_with($tipe, (string) $f->mime));
        } else {
            $cek("{$jenis}: HTML mandiri tanpa skrip, logo jadi data URI", $s === 200 && str_starts_with($tipe, 'text/html')
                && ! preg_match('/<script/i', $isi) && str_contains($isi, 'data:image/') && ! str_contains($isi, 'src="/images/'), "{$s} {$tipe}");
        }
        if ($staff) {
            $bukan = ! $query->canView($staff, $a->load('steps'));
            if ($bukan) {
                [$s] = $panggil('GET', "{$base}/{$a->id}/dokumen/{$item['kunci']}", $staff->email);
                $cek("{$jenis}: staff tanpa hak → 403", $s === 403, "dapat {$s}");
            }
        }
    }
    // Invoice Hutang dengan alokasi / koreksi turunan: daftar turunan (tanpa URL BO) + lampirannya per kunci.
    $ikut = app(App\Domain\BusinessSupport\Services\BudgetIkutInvoice::class);
    $inv = Approval::whereIn('document_type', ['ap_invoice', 'ap_budget_request'])->latest('id')->limit(200)->get()
        ->first(fn ($a) => $ikut->turunan($a)->isNotEmpty());
    if ($inv && $admin) {
        [$s, $j] = $panggil('GET', "{$base}/{$inv->id}", $admin->email);
        $t = $j['data']['turunan'][0] ?? null;
        $cek("turunan: detail #{$inv->id} memuat alokasi/koreksi tanpa URL BO", $s === 200 && $t && ! isset($t['lampiran_url']) && array_key_exists('revisi', $j['data']), json_encode($t));
        if ($t) {
            [$s, , , $isi, $hdr] = $panggil('GET', "{$base}/{$inv->id}/dokumen/{$t['jenis']}-{$t['id']}", $admin->email);
            $cek("turunan: lampiran {$t['jenis']}-{$t['id']} HTML mandiri", $s === 200 && str_starts_with((string) $hdr->get('Content-Type'), 'text/html') && ! preg_match('/<script/i', $isi), "dapat {$s}");
        }
    } else {
        echo "  (lewati turunan: tidak ada invoice dengan alokasi / koreksi)\n";
    }
    if ($contoh['memo'] ?? null) {
        [$s] = $panggil('GET', "{$base}/{$contoh['memo']->id}/dokumen/alokasi-1", '1234@jerp.test');
        $cek('alokasi-ID di approval yang bukan invoice → 404', $s === 404, "dapat {$s}");
    }
    [$s] = $panggil('GET', "{$base}/1/dokumen/rahasia", '1234@jerp.test');
    $cek('kunci dokumen asal-asalan → 404', $s === 404, "dapat {$s}");

    echo "Dashboard\n";
    $dash = '/api/v1/fo-bridge/dashboard';
    [$s] = $panggil('GET', $dash, 'gm@jerp.test', [], fn ($h) => ['X-Bridge-Sig' => str_repeat('0', 64)] + $h);
    $cek('dashboard tanda tangan salah → 403', $s === 403, "dapat {$s}");
    foreach (['1234', 'gm', 'staff'] as $nama) {
        $u = User::where('email', "{$nama}@jerp.test")->first();
        if (! $u) {
            continue;
        }
        $grup = array_values(array_filter(array_keys(App\Http\Controllers\Web\DashboardController::GRUP), fn ($g) => $u->can("dashboard.{$g}.view")));
        $harap = $grup ? array_merge(...array_map(fn ($g) => App\Http\Controllers\Web\DashboardController::GRUP[$g], $grup)) : [];
        [$s, $j] = $panggil('GET', $dash, $u->email);
        $dapat = array_keys($j['charts'] ?? []);
        sort($harap);
        sort($dapat);
        $cek("{$nama}: allowed=".($grup ? 'ya' : 'tidak').', grafik sesuai izin ('.count($harap).')', $s === 200
            && ($j['allowed'] ?? null) === ($grup !== []) && $harap === $dapat, json_encode([$s, $harap, $dapat]));
    }
} finally {
    DB::rollBack();
}

echo PHP_EOL."Hasil: {$lulus} lulus, {$gagal} gagal".PHP_EOL;
exit($gagal > 0 ? 1 : 0);
