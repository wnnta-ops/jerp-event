<?php

declare(strict_types=1);

/*
 * Uji cara pembayaran bon sementara — Reguler vs Debit VA.
 *
 *   reguler  : transfer/tunai, alur seperti selama ini.
 *   debit_va : lewat Virtual Account, dan tanggal expired VA WAJIB dicatat.
 *
 * KENAPA TANGGAL EXPIRED-NYA DIJAGA KERAS. Jurnal bon sementara terbentuk pada saat pembayaran
 * dicatat, BUKAN saat uangnya benar-benar berpindah. VA yang lewat tanggalnya tidak bisa lagi
 * didebit — jadi bila tanggal itu tidak tercatat, buku besar sudah menunjukkan uang keluar
 * sementara rekeningnya tidak pernah terdebit, dan selisihnya baru ketahuan saat rekonsiliasi
 * bank. Karena itu kewajibannya ditegakkan di TIGA lapis: layar, validasi request, dan service.
 *
 * DIJAGA JUGA: pembayaran reguler tidak boleh ikut terkena syarat itu — bila ikut, seluruh
 * pembayaran biasa mendadak menuntut tanggal yang tidak ada artinya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-bon-pembayaran-reguler-va.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

$svc = app(CashAdvanceService::class);
$ctrl = new CashAdvanceController();
$user = \App\Models\User::query()->find((int) DB::table('users')->orderBy('id')->value('id'));
auth()->setUser($user);

DB::beginTransaction();

try {
    echo "\n=== 0. Kolomnya sudah turun ===\n";

    $cek('kolom payment_method ada', Schema::hasColumn('bs_cash_advances', 'payment_method'));
    $cek('kolom va_expired_date ada', Schema::hasColumn('bs_cash_advances', 'va_expired_date'));

    $kolom = DB::selectOne("SELECT COLUMN_DEFAULT d, IS_NULLABLE n FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name='bs_cash_advances' AND column_name='payment_method'");
    $cek("bawaan payment_method 'reguler'", ($kolom->d ?? '') === 'reguler', (string) ($kolom->d ?? '?'));
    $expKol = DB::selectOne("SELECT IS_NULLABLE n FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name='bs_cash_advances' AND column_name='va_expired_date'");
    // Nullable disengaja: hanya bermakna pada Debit VA. NOT NULL akan menolak seluruh
    // pembayaran reguler.
    $cek('va_expired_date boleh kosong', ($expKol->n ?? '') === 'YES', (string) ($expKol->n ?? '?'));

    $kas = (int) DB::table('bs_accounts')->where('is_postable', true)
        ->where('code', 'like', '10101%')->orderBy('code')->value('id');
    if (! $kas) {
        throw new RuntimeException('tidak ada akun kas/bank untuk dijadikan fikstur');
    }

    /** Bon siap bayar — dibuat OFFLINE supaya gerbang persetujuannya tidak ikut menghalangi. */
    $bonSiap = fn (string $tujuan) => $svc->issue([
        'advance_date' => '2026-09-04', 'holder' => 'UJI Pemegang — Staff',
        'purpose' => $tujuan, 'amount' => 750000, 'approval_mode' => 'offline',
    ], (int) $user->id);

    /** Catat pembayaran lewat controller (validasi request ikut berjalan). */
    $bayar = function (BsCashAdvance $adv, array $isian) use ($ctrl, $user) {
        $req = Request::create('/x', 'POST', $isian);
        $req->setUserResolver(fn () => $user);
        $req->setLaravelSession(app('session.store'));
        app('session.store')->flush();

        try {
            $ctrl->pay($req, $adv, app(CashAdvanceService::class));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['validasi' => $e->validator->errors()->toArray(), 'galat' => null];
        } catch (\Throwable $e) {
            return ['validasi' => [], 'galat' => $e->getMessage(), 'ledak' => true];
        }

        return ['validasi' => [], 'galat' => app('session.store')->get('error')];
    };

    echo "\n=== 1. Pembayaran REGULER — alurnya tidak berubah ===\n";

    $reg = $bonSiap('UJI bayar reguler');
    $hasil = $bayar($reg, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas, 'payment_method' => 'reguler']);
    $cek('diterima tanpa galat', empty($hasil['galat']) && $hasil['validasi'] === [], json_encode($hasil));
    $segar = $reg->fresh();
    $cek('tersimpan sebagai reguler', $segar->payment_method === 'reguler', (string) $segar->payment_method);
    $cek('tanpa tanggal expired', $segar->va_expired_date === null, (string) $segar->va_expired_date);
    $cek('jurnalnya terbentuk', $segar->journal_id !== null);
    $cek('statusnya outstanding', $segar->status === 'outstanding', (string) $segar->status);

    // Reguler TIDAK boleh ikut menuntut tanggal expired.
    $reg2 = $bonSiap('UJI reguler tanpa expired');
    $h2 = $bayar($reg2, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas, 'payment_method' => 'reguler']);
    $cek('reguler tidak menuntut tanggal expired', ! isset($h2['validasi']['va_expired_date']), json_encode($h2['validasi']));

    /* Reguler yang IKUT MENGIRIM tanggal expired tidak boleh menyimpannya.
       Ini bukan kasus karangan: kolomnya disembunyikan saat pilihan berpindah ke Reguler,
       tetapi nilainya masih tersimpan di form dan tetap terkirim. Menyimpannya berarti ada
       pembayaran reguler yang memajang tanggal kedaluwarsa VA — padahal tidak ada VA-nya. */
    $regExp = $bonSiap('UJI reguler membawa expired');
    $bayar($regExp, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas,
        'payment_method' => 'reguler', 'va_expired_date' => '2026-09-30']);
    $cek('reguler tidak menyimpan tanggal expired yang terbawa',
        $regExp->fresh()->va_expired_date === null, (string) $regExp->fresh()->va_expired_date);
    $cek('dan tetap tercatat reguler', $regExp->fresh()->payment_method === 'reguler');

    // Tanpa menyebut caranya sama sekali (kiriman lama) → reguler.
    $lama = $bonSiap('UJI tanpa menyebut cara bayar');
    $h3 = $bayar($lama, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas]);
    $cek('kiriman tanpa cara bayar tetap diterima', empty($h3['galat']) && $h3['validasi'] === [], json_encode($h3));
    $cek('dan tersimpan sebagai reguler', $lama->fresh()->payment_method === 'reguler',
        (string) $lama->fresh()->payment_method);

    echo "\n=== 2. Debit VA TANPA tanggal expired DITOLAK ===\n";

    $va1 = $bonSiap('UJI VA tanpa expired');
    $h4 = $bayar($va1, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas, 'payment_method' => 'debit_va']);
    $cek('ditolak validasi', isset($h4['validasi']['va_expired_date']), json_encode($h4));
    $cek('pesannya menyebut tanggal expired VA',
        str_contains(implode(' ', $h4['validasi']['va_expired_date'] ?? []), 'expired VA'),
        json_encode($h4['validasi']));
    $cek('tidak ada jurnal yang terbentuk', $va1->fresh()->journal_id === null);
    $cek('statusnya masih requested', $va1->fresh()->status === 'requested', (string) $va1->fresh()->status);

    echo "\n=== 3. Debit VA DENGAN tanggal expired diterima ===\n";

    $va2 = $bonSiap('UJI VA lengkap');
    $h5 = $bayar($va2, [
        'paid_date' => '2026-09-04', 'cash_account_id' => $kas,
        'payment_method' => 'debit_va', 'va_expired_date' => '2026-09-18',
    ]);
    $cek('diterima tanpa galat', empty($h5['galat']) && $h5['validasi'] === [], json_encode($h5));
    $segarVa = $va2->fresh();
    $cek('tersimpan sebagai debit_va', $segarVa->payment_method === 'debit_va', (string) $segarVa->payment_method);
    $cek('tanggal expired tersimpan',
        $segarVa->va_expired_date?->toDateString() === '2026-09-18', (string) $segarVa->va_expired_date);
    $cek('jurnalnya terbentuk', $segarVa->journal_id !== null);

    // Jurnalnya HARUS sama dengan reguler — yang berbeda cara uangnya berpindah, bukan akuntansinya.
    $barisVa = DB::table('bs_journal_lines')->where('journal_id', $segarVa->journal_id)
        ->orderBy('id')->get(['account_id', 'debit', 'credit']);
    $barisReg = DB::table('bs_journal_lines')->where('journal_id', $segar->journal_id)
        ->orderBy('id')->get(['account_id', 'debit', 'credit']);
    $cek('jurnal VA sama bentuknya dengan jurnal reguler',
        $barisVa->pluck('account_id')->all() === $barisReg->pluck('account_id')->all()
            && $barisVa->count() === 2,
        json_encode($barisVa));

    echo "\n=== 4. Tanggal expired tidak boleh mendahului tanggal transfer ===\n";

    // VA yang kedaluwarsa sebelum tanggal transfernya tidak pernah bisa didebit.
    $va3 = $bonSiap('UJI VA expired mundur');
    $h6 = $bayar($va3, [
        'paid_date' => '2026-09-10', 'cash_account_id' => $kas,
        'payment_method' => 'debit_va', 'va_expired_date' => '2026-09-01',
    ]);
    $cek('ditolak', isset($h6['validasi']['va_expired_date']), json_encode($h6));
    $cek('tidak ada jurnal yang terbentuk', $va3->fresh()->journal_id === null);

    // Tanggal expired SAMA dengan tanggal transfer masih sah (VA berlaku hari itu juga).
    $va4 = $bonSiap('UJI VA expired sama hari');
    $h7 = $bayar($va4, [
        'paid_date' => '2026-09-10', 'cash_account_id' => $kas,
        'payment_method' => 'debit_va', 'va_expired_date' => '2026-09-10',
    ]);
    $cek('expired = tanggal transfer diterima', empty($h7['galat']) && $h7['validasi'] === [], json_encode($h7));

    echo "\n=== 5. Service menjaganya sendiri, bukan hanya validasi request ===\n";

    /* Lapis terakhir. Validasi request hanya berlaku untuk yang lewat controller; pemanggilan
       service langsung — job, perintah artisan, atau kode baru — tidak melewatinya. Tanpa
       penjagaan di service, jalur semacam itu bisa membentuk jurnal untuk VA tanpa masa
       berlaku, dan tidak ada yang menandainya. */
    /* KODE GALATNYA yang diperiksa, bukan sekadar "ada exception".
       Kedua penjaga di service melempar BusinessRuleException dan pesannya sama-sama memuat
       kata "expired". Bila penjaga PERTAMA (wajib ada tanggalnya) dilepas, eksekusi jatuh ke
       penjaga kedua: strtotime('') bernilai false, sehingga false < strtotime(paid_date)
       terbaca benar dan yang terlempar justru "tidak boleh mendahului". Pemeriksaan yang hanya
       menuntut "ada exception" akan tetap lulus — uji mutasi menemukan celah ini. */
    $kode = function (callable $aksi): ?string {
        try {
            $aksi();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return null;
    };

    $va5 = $bonSiap('UJI VA lewat service');
    $k1 = $kode(fn () => $svc->pay($va5, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas, 'payment_method' => 'debit_va'], (int) $user->id));
    $cek('service menolak VA tanpa tanggal expired', $k1 !== null, 'tidak ada penolakan');
    $cek('penolakannya memang karena tanggalnya TIDAK ADA', $k1 === 'ADV_VA_NO_EXPIRY', (string) $k1);
    $cek('tidak ada jurnal yang terbentuk', $va5->fresh()->journal_id === null);

    $va6 = $bonSiap('UJI VA mundur lewat service');
    $k2 = $kode(fn () => $svc->pay($va6, ['paid_date' => '2026-09-10', 'cash_account_id' => $kas,
        'payment_method' => 'debit_va', 'va_expired_date' => '2026-09-01'], (int) $user->id));
    $cek('service menolak expired yang mendahului transfer', $k2 === 'ADV_VA_EXPIRED_BEFORE_PAID', (string) $k2);

    // Nilai asing tidak boleh lolos sebagai cara pembayaran tak dikenal.
    $va7 = $bonSiap('UJI cara bayar asing');
    $svc->pay($va7, ['paid_date' => '2026-09-04', 'cash_account_id' => $kas, 'payment_method' => 'DEBIT-VA-typo'], (int) $user->id);
    $cek('nilai asing jatuh ke reguler', $va7->fresh()->payment_method === 'reguler',
        (string) $va7->fresh()->payment_method);
    $cek('dan tidak menyimpan tanggal expired', $va7->fresh()->va_expired_date === null);

    echo "\n=== 6. Layar menerima daftar cara pembayarannya ===\n";

    $req = Request::create('/s', 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $props = json_decode((string) $ctrl->show($req, $bonSiap('UJI props'))->toResponse($req)->getContent(), true)['props'] ?? [];
    $cek('paymentMethods dikirim ke layar',
        array_keys($props['paymentMethods'] ?? []) === ['reguler', 'debit_va'],
        json_encode(array_keys($props['paymentMethods'] ?? [])));

    // Layar juga harus benar-benar memasang syaratnya, bukan hanya menampilkan pilihannya.
    $srcShow = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/Advances/Show.vue')) ?: '';
    $cek('layar mengirim payment_method & va_expired_date',
        str_contains($srcShow, "payment_method: 'reguler'") && str_contains($srcShow, "va_expired_date: ''"));
    $cek('tombol bayar ikut ditahan saat expired VA kosong',
        str_contains($srcShow, '|| vaKurangExpired'), 'tombolnya tidak menghitung vaKurangExpired');
    $cek('kolom expired hanya muncul pada Debit VA',
        str_contains($srcShow, "v-if=\"payForm.payment_method === 'debit_va'\""));

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
