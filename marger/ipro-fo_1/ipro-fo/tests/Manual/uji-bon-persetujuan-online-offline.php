<?php

declare(strict_types=1);

/*
 * Uji cara persetujuan bon sementara — Online vs Offline, dipilih PER PENGAJUAN.
 *
 *   online  : jenjang berjalan di aplikasi. Pembayaran & jurnal baru terbuka setelah SELURUH
 *             tanda tangan (pengaju s.d. Manager Finance) terpenuhi.
 *   offline : tanda tangannya di kertas. Jenjang di aplikasi dimatikan, dan pembayaran boleh
 *             dicatat tanpa menunggunya.
 *
 * DUA ARAH KESALAHAN YANG SAMA-SAMA MAHAL, dan keduanya dijaga di sini:
 *
 *   · Offline yang tidak benar-benar membuka gerbang → bon offline mustahil dibayar, dan
 *     satu-satunya jalan keluarnya adalah menandatangani sembilan slot yang memang tidak
 *     dipakai.
 *   · Online yang ikut terbuka → uang keluar tanpa satu pun persetujuan. Inilah yang paling
 *     berbahaya, sebab tidak ada yang menandainya: jurnalnya terbentuk rapi seperti biasa.
 *
 * BAWAANNYA ONLINE, termasuk untuk bon lama yang kolomnya masih NULL — membacanya sebagai
 * offline akan membuka pembayaran seluruh bon lama sekaligus.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-bon-persetujuan-online-offline.php
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

    $cek('kolom approval_mode ada di bs_cash_advances',
        Schema::hasColumn('bs_cash_advances', 'approval_mode'),
        'migrasi 2026_09_04_100000 belum dijalankan');

    /** Buat bon dengan cara persetujuan tertentu. Nominalnya > 1 juta → rantai penuh 9 jenjang. */
    $buat = function (?string $mode, float $nominal = 5000000) use ($svc, $user): BsCashAdvance {
        $data = [
            'advance_date' => '2026-09-04', 'holder' => 'UJI Pemegang — Staff',
            'purpose' => 'UJI persetujuan '.($mode ?? 'bawaan'), 'amount' => $nominal,
        ];
        if ($mode !== null) {
            $data['approval_mode'] = $mode;
        }

        return $svc->issue($data, (int) $user->id);
    };

    echo "\n=== 1. Pilihannya tersimpan per pengajuan ===\n";

    $online = $buat('online');
    $offline = $buat('offline');
    $bawaan = $buat(null);

    $cek('bon online tersimpan sebagai online', $online->approval_mode === 'online', (string) $online->approval_mode);
    $cek('bon offline tersimpan sebagai offline', $offline->approval_mode === 'offline', (string) $offline->approval_mode);
    $cek('tanpa pilihan → online (perilaku lama)', $bawaan->approval_mode === 'online', (string) $bawaan->approval_mode);

    // Dua bon berdampingan boleh berbeda caranya — itulah arti "per pengajuan".
    $cek('dua bon berdampingan boleh berbeda caranya',
        $online->approval_mode !== $offline->approval_mode);

    // Nilai asing tidak boleh tersimpan apa adanya.
    $aneh = $svc->issue([
        'advance_date' => '2026-09-04', 'holder' => 'UJI Aneh', 'purpose' => 'UJI',
        'amount' => 100000, 'approval_mode' => 'ONLINE-typo',
    ], (int) $user->id);
    $cek('nilai asing jatuh ke online, bukan diterima apa adanya',
        $aneh->approval_mode === 'online', (string) $aneh->approval_mode);

    echo "\n=== 2. Jenjangnya mati pada offline, hidup pada online ===\n";

    $cek('online menuntut 9 jenjang (nominal > 1 juta)', count($online->requiredSigners()) === 9,
        (string) count($online->requiredSigners()));
    $cek('offline tidak menuntut satu jenjang pun', $offline->requiredSigners() === [],
        json_encode($offline->requiredSigners()));

    $cek('online BELUM lengkap persetujuannya', $online->isFullyApproved() === false);
    $cek('offline terbaca lengkap — gerbang pembayarannya terbuka', $offline->isFullyApproved() === true);

    echo "\n=== 3. Tanda tangan digital ditolak pada bon offline ===\n";

    // Kalau tidak ditolak, bon offline bisa punya "persetujuan aplikasi" separuh jalan yang
    // tidak berarti apa-apa — dan riwayatnya lalu menyesatkan saat diaudit.
    $pesan = null;
    try {
        $svc->approve($offline, 'pengaju', (int) $user->id, 'Super Admin');
    } catch (BusinessRuleException $e) {
        $pesan = $e->getMessage();
    }
    $cek('approve pada bon offline ditolak', $pesan !== null);
    $cek('pesannya menyebut Persetujuan Offline, bukan soal nominal',
        str_contains((string) $pesan, 'Offline'), (string) $pesan);
    $cek('tidak ada approval yang tercatat', ($offline->fresh()->approvals ?? []) === []);

    // Sedangkan pada bon online, slot pertamanya tetap bisa disetujui seperti biasa.
    $svc->approve($online, 'pengaju', (int) $user->id, 'Super Admin');
    $cek('approve pada bon online tetap berjalan',
        isset($online->fresh()->approvals['pengaju']));

    echo "\n=== 4. Gerbang pembayaran: offline lewat, online tertahan ===\n";

    $kas = (int) DB::table('bs_accounts')->where('is_postable', true)
        ->where('code', 'like', '10101%')->orderBy('code')->value('id');
    if (! $kas) {
        throw new RuntimeException('tidak ada akun kas/bank untuk dijadikan fikstur');
    }
    $bayar = function (BsCashAdvance $adv) use ($ctrl, $user, $kas) {
        $req = Request::create('/x', 'POST', ['paid_date' => '2026-09-04', 'cash_account_id' => $kas]);
        $req->setUserResolver(fn () => $user);
        $req->setLaravelSession(app('session.store'));
        app('session.store')->flush();

        try {
            $ctrl->pay($req, $adv, app(CashAdvanceService::class));
        } catch (\Throwable $e) {
            return ['galat' => $e->getMessage(), 'ledak' => true];
        }

        return ['galat' => app('session.store')->get('error'), 'ledak' => false];
    };

    // ONLINE, baru 1 dari 9 tanda tangan → HARUS tertahan. Ini penjagaan yang paling penting:
    // longgar sedikit di sini berarti uang keluar tanpa persetujuan.
    $hasilOnline = $bayar($online->fresh());
    $cek('bon online yang belum lengkap DITOLAK', ! empty($hasilOnline['galat']), json_encode($hasilOnline));
    $cek('penolakannya rapi, bukan exception', $hasilOnline['ledak'] === false);
    $cek('bon online belum berjurnal', $online->fresh()->journal_id === null);
    $cek('statusnya belum berubah', $online->fresh()->status === 'requested');

    // OFFLINE, tanpa satu pun tanda tangan digital → HARUS bisa dibayar.
    $hasilOffline = $bayar($offline->fresh());
    $cek('bon offline BOLEH dibayar tanpa tanda tangan aplikasi',
        empty($hasilOffline['galat']), json_encode($hasilOffline));
    $segar = $offline->fresh();
    $cek('jurnalnya terbentuk', $segar->journal_id !== null, 'journal_id kosong');
    $cek('statusnya menjadi outstanding', $segar->status === 'outstanding', (string) $segar->status);

    echo "\n=== 5. Bon LAMA & jalur yang tidak menyebut caranya tetap ONLINE ===\n";

    /* Ini penjagaan terpenting dari seluruh perubahan: bon yang caranya TIDAK disebut harus
       jatuh ke online. Terbaca offline berarti pembayaran seluruh bon lama terbuka sekaligus —
       tanpa satu pun persetujuan, dan tanpa satu pun galat yang menandainya.

       Dijaga di DUA lapis, dan keduanya diperiksa:
         · basis data — kolomnya NOT NULL berbawaan 'online', jadi baris yang sudah ada saat
           migrasi turun ikut terisi 'online', dan jalur mana pun yang menyisipkan tanpa
           menyebut kolom ini tetap mendapat 'online';
         · model — ?? 'online' menjaga instance yang atributnya belum/ tidak dimuat. */
    $kolom = DB::selectOne("SELECT IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'bs_cash_advances' AND column_name = 'approval_mode'");
    $cek('kolomnya tidak boleh kosong', ($kolom->n ?? '') === 'NO', (string) ($kolom->n ?? '?'));
    $cek("bawaannya 'online'", ($kolom->d ?? '') === 'online', (string) ($kolom->d ?? '?'));

    // Sisipan yang TIDAK menyebut approval_mode — mewakili baris lama & jalur lama.
    $idLama = (int) DB::table('bs_cash_advances')->insertGetId([
        'tenant_id' => $tenantId, 'advance_number' => 'UJI-LAMA-1', 'advance_date' => '2026-09-04',
        'holder' => 'UJI Bon Lama', 'purpose' => 'UJI tanpa kolom cara persetujuan',
        'advance_account_id' => $online->advance_account_id, 'amount' => 5000000,
        'status' => 'requested', 'created_by' => (int) $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lama = BsCashAdvance::find($idLama);
    $cek("baris tanpa kolom itu terisi 'online'", $lama->approval_mode === 'online', (string) $lama->approval_mode);
    $cek('bon lama terbaca ONLINE', $lama->isOfflineApproval() === false);
    $cek('bon lama tetap menuntut jenjang', count($lama->requiredSigners()) === 9,
        (string) count($lama->requiredSigners()));
    $cek('bon lama tetap tertahan pembayarannya', ! empty($bayar($lama)['galat']));

    // Lapis model: instance yang atributnya tidak dimuat sama sekali.
    $kosong = new BsCashAdvance();
    $cek('model tanpa atribut apa pun terbaca ONLINE', $kosong->isOfflineApproval() === false);

    echo "\n=== 6. Layar detail membedakan keduanya ===\n";

    $props = function (BsCashAdvance $adv) use ($ctrl, $user): array {
        $req = Request::create('/s', 'GET');
        $req->setUserResolver(fn () => $user);
        $req->headers->set('X-Inertia', 'true');
        $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
        $isi = json_decode((string) $ctrl->show($req, $adv)->toResponse($req)->getContent(), true) ?: [];

        return $isi['props'] ?? [];
    };

    $pOnline = $props(BsCashAdvance::find($online->id));
    $pOffline = $props(BsCashAdvance::find($offline->id));

    $cek('layar tahu bon ini online', ($pOnline['approvalMode'] ?? '') === 'online', (string) ($pOnline['approvalMode'] ?? ''));
    $cek('layar tahu bon ini offline', ($pOffline['approvalMode'] ?? '') === 'offline', (string) ($pOffline['approvalMode'] ?? ''));
    // requiredSigners kosong itulah yang menyembunyikan kartu jenjang pada bon offline.
    $cek('kartu jenjang disembunyikan pada offline', ($pOffline['requiredSigners'] ?? null) === []);
    $cek('kartu jenjang tetap tampil pada online', count($pOnline['requiredSigners'] ?? []) === 9);
    $cek('daftar pilihan caranya ikut dikirim',
        array_keys($pOnline['approvalModes'] ?? []) === ['online', 'offline'],
        json_encode(array_keys($pOnline['approvalModes'] ?? [])));

    echo "\n=== 7. Cetak pengajuannya TIDAK berubah ===\n";

    // Justru pada bon offline kertasnya yang menjadi tempat tanda tangan — formulirnya harus
    // tetap terbit, dan tetap memakai bentuk berjenjang sesuai nominalnya.
    $cetak = function (BsCashAdvance $adv) use ($ctrl, $user): string {
        $req = Request::create('/p', 'GET');
        $req->setUserResolver(fn () => $user);

        return (string) $ctrl->print($req, $adv)->getContent();
    };
    /* Bon yang dicetak harus yang masih BERSTATUS 'requested' — print() memilih formulir
       PENGAJUAN hanya untuk status itu; sesudah dibayar yang terbit formulir pengeluaran.
       Karena itu dibuat bon offline baru di sini, bukan memakai $offline yang sudah dibayar
       pada bagian 4. */
    $offlineBaru = $buat('offline');
    $htmlOffline = $cetak($offlineBaru);
    $cek('formulir pengajuan bon offline tetap terbit', strlen($htmlOffline) > 500, strlen($htmlOffline).' byte');
    $cek('kotak tanda tangan berjenjangnya masih ada',
        str_contains($htmlOffline, 'Manager Finance'), 'kotak tanda tangan tidak ditemukan');
    $cek('kotak Manager Accounting juga masih ada', str_contains($htmlOffline, 'Manager Accounting'));

    // Bentuk formulirnya tetap ditentukan NOMINAL, bukan cara persetujuannya: nominal ≥ 1 juta
    // memakai formulir 9 kotak, apa pun caranya.
    $htmlOnline = $cetak(BsCashAdvance::find($online->id));
    $cek('formulir online & offline sama bentuknya untuk nominal yang sama',
        str_contains($htmlOnline, 'Manager Finance') === str_contains($htmlOffline, 'Manager Finance'));

    echo "\n=== 8. store() memvalidasi pilihannya, bukan menelan diam-diam ===\n";

    /* Bagian 1 memanggil service langsung, jadi ia tidak melihat aturan validasi di store().
       Tanpa pemeriksaan ini, melepas aturannya tetap lolos seluruh uji: servicenya memang
       membetulkan nilai asing menjadi 'online'. Bedanya, tanpa validasi orang yang salah
       kirim tidak pernah diberi tahu bahwa pilihannya diabaikan — bonnya berjalan online
       padahal ia mengira offline, dan itu baru ketahuan saat pembayarannya tertahan. */
    $kirimStore = function (array $tambahan) use ($user) {
        $ctrl = new CashAdvanceController();
        $req = Request::create('/x', 'POST', array_merge([
            'advance_date' => '2026-09-04', 'holder' => 'UJI Store', 'purpose' => 'UJI validasi',
            'amount' => 250000,
        ], $tambahan));
        $req->setUserResolver(fn () => $user);
        $req->setLaravelSession(app('session.store'));

        try {
            $ctrl->store($req, app(CashAdvanceService::class));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->validator->errors()->toArray();
        }

        return [];
    };

    $galatAsing = $kirimStore(['approval_mode' => 'ONLINE-typo']);
    $cek('pilihan asing ditolak validasi', isset($galatAsing['approval_mode']), json_encode($galatAsing));
    $cek('offline diterima validasi', ! isset($kirimStore(['approval_mode' => 'offline'])['approval_mode']));
    $cek('online diterima validasi', ! isset($kirimStore(['approval_mode' => 'online'])['approval_mode']));
    $cek('tanpa pilihan tetap diterima (jatuh ke online)', ! isset($kirimStore([])['approval_mode']));

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
