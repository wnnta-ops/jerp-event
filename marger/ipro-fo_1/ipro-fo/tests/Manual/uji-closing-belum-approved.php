<?php

declare(strict_types=1);

/**
 * Uji: periode tidak bisa ditutup selama masih ada jurnal yang belum Approved.
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-closing-belum-approved.php
 *
 * Menutup periode berarti mengunci jurnalnya dari pengeditan. Jurnal Baru (draft) dan Jurnal
 * Final belum efektif di General Ledger — angkanya belum masuk laporan mana pun. Mengunci
 * periode saat keduanya masih ada akan membekukan mereka dalam keadaan setengah jadi: tidak
 * terhitung di laporan, tetapi juga tidak bisa lagi di-approve maupun diperbaiki tanpa
 * membatalkan closing-nya lebih dulu.
 *
 * Yang paling menentukan: penjaganya ada di PeriodClosingService, bukan di satu controller —
 * sehingga SELURUH pintu penutupan terjaga sama. Penjaga yang bisa dilewati dengan menekan
 * tombol lain bukan penjaga.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\PeriodClosingService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Gl\GeneralLedgerController;
use App\Http\Controllers\Web\BusinessSupport\Gl\PeriodClosingController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$svc = app(PeriodClosingService::class);
$TAHUN = 2039;   // tahun jauh dari data nyata, supaya tidak bercampur

/** Coba tutup periode; pulangkan pesan penolakannya, atau '' bila berhasil. */
$tutup = function (int $bulan) use ($svc, $TAHUN): string {
    try {
        $svc->closePeriod($TAHUN, $bulan);

        return '';
    } catch (BusinessRuleException $e) {
        return $e->getMessage();
    }
};

$statusPeriode = fn (int $bulan) => BsFiscalPeriod::where('year', $TAHUN)->where('month', $bulan)->value('status');

DB::beginTransaction();
try {
    $akun = BsAccount::where('is_postable', true)->orderBy('code')->limit(2)->get();
    $cek('dua akun tersedia', $akun->count() === 2);

    $nomor = 0;
    $buatJurnal = function (int $bulan, string $status) use ($TAHUN, $akun, &$nomor) {
        $nomor++;
        $j = BsJournal::create([
            'journal_number' => 'UJI/CLOSE/'.str_pad((string) $nomor, 4, '0', STR_PAD_LEFT),
            'journal_date' => sprintf('%04d-%02d-10', $TAHUN, $bulan),
            'period_year' => $TAHUN, 'period_month' => $bulan,
            'source' => 'memorial', 'description' => 'Uji penjaga closing', 'status' => $status,
            'total_debit' => 1000, 'total_credit' => 1000,
        ]);
        $j->lines()->create(['account_id' => $akun[0]->id, 'debit' => 1000, 'credit' => 0]);
        $j->lines()->create(['account_id' => $akun[1]->id, 'debit' => 0, 'credit' => 1000]);

        return $j;
    };

    echo "\n=== 1. Periode bersih tetap bisa ditutup ===\n";

    // Penjaga yang menolak semuanya sama saja dengan mematikan fiturnya.
    $cek('bulan tanpa jurnal berhasil ditutup', $tutup(1) === '');
    $cek('statusnya benar-benar closed', $statusPeriode(1) === 'closed', (string) $statusPeriode(1));

    $buatJurnal(2, 'posted');
    $cek('bulan yang semua jurnalnya Approved berhasil ditutup', $tutup(2) === '');
    $cek('statusnya benar-benar closed', $statusPeriode(2) === 'closed', (string) $statusPeriode(2));

    echo "\n=== 2. Jurnal Baru menahan closing ===\n";

    $buatJurnal(3, 'draft');
    $pesan = $tutup(3);
    $cek('ditolak', $pesan !== '', 'justru berhasil ditutup');
    $cek('menyebut jumlah Jurnal Baru', str_contains($pesan, '1 Jurnal Baru'), $pesan);
    // "0 Jurnal Final" hanya menambah kebingungan.
    $cek('tidak menyebut Jurnal Final yang nol', ! str_contains($pesan, 'Jurnal Final'), $pesan);
    $cek('menyebut periodenya', str_contains($pesan, "3/{$TAHUN}"), $pesan);
    $cek('menyebut syaratnya', str_contains($pesan, 'Approved'), $pesan);
    // Yang paling penting: periodenya benar-benar TIDAK tertutup.
    $cek('periodenya tidak jadi tertutup', $statusPeriode(3) !== 'closed', (string) $statusPeriode(3));

    echo "\n=== 3. Jurnal Final juga menahan closing ===\n";

    $buatJurnal(4, 'final');
    $pesan = $tutup(4);
    $cek('ditolak', $pesan !== '');
    $cek('menyebut jumlah Jurnal Final', str_contains($pesan, '1 Jurnal Final'), $pesan);
    $cek('tidak menyebut Jurnal Baru yang nol', ! str_contains($pesan, 'Jurnal Baru'), $pesan);

    echo "\n=== 4. Keduanya disebutkan beserta jumlahnya ===\n";

    for ($i = 0; $i < 3; $i++) {
        $buatJurnal(5, 'draft');
    }
    for ($i = 0; $i < 2; $i++) {
        $buatJurnal(5, 'final');
    }
    $buatJurnal(5, 'posted');   // yang sudah Approved tidak ikut dihitung
    $pesan = $tutup(5);
    $cek('menyebut 3 Jurnal Baru dan 2 Jurnal Final',
        str_contains($pesan, '3 Jurnal Baru dan 2 Jurnal Final'), $pesan);
    echo "        pesannya: {$pesan}\n";

    echo "\n=== 5. Jurnal yang dihapus tidak ikut menahan ===\n";

    $terhapus = $buatJurnal(6, 'draft');
    $cek('sebelum dihapus, closing tertahan', $tutup(6) !== '');
    $terhapus->delete();
    $cek('sesudah dihapus, closing bisa dilakukan', $tutup(6) === '', $tutup(6));

    echo "\n=== 6. Seluruh pintu penutupan terjaga sama ===\n";

    $user = User::query()->get()->first(fn ($u) => $u->can('businesssupport.approval.act'));
    $cek('ada pengguna berwenang menutup periode', $user !== null);

    if ($user) {
        $post = function (string $rute, array $data) use ($user) {
            $req = Illuminate\Http\Request::create($rute, 'POST', $data);
            $req->setUserResolver(fn () => $user);

            return $req;
        };

        // Pintu 1 — Closing Fiskal. Penolakannya harus jadi pesan, BUKAN galat 500.
        $buatJurnal(7, 'draft');
        $r = app(PeriodClosingController::class)->close($post('/x', ['year' => $TAHUN, 'month' => 7]), $svc);
        $sesi = $r->getSession()?->get('error') ?? ($r->getSession()?->get('success') ?? '');
        $cek('Closing Fiskal menolak dengan pesan, bukan galat',
            is_string($sesi) && str_contains($sesi, 'Jurnal Baru'), (string) $sesi);
        $cek('periodenya tidak tertutup', $statusPeriode(7) !== 'closed', (string) $statusPeriode(7));

        // Pintu 2 — Late Closing. "Terlambat ditutup" bukan berarti "boleh ditutup walau
        // jurnalnya belum selesai".
        $buatJurnal(8, 'final');
        $r = app(PeriodClosingController::class)->lateClose(
            $post('/x', ['year' => $TAHUN, 'month' => 8, 'note' => 'uji']), $svc);
        $sesi = $r->getSession()?->get('error') ?? '';
        $cek('Late Closing ikut menolak', is_string($sesi) && str_contains($sesi, 'Jurnal Final'), (string) $sesi);
        $cek('periodenya tidak tertutup', $statusPeriode(8) !== 'closed', (string) $statusPeriode(8));

        // Pintu 3 — layar General Ledger. Dulu menulis sendiri ke bs_fiscal_periods sehingga
        // sepenuhnya melewati syarat ini.
        $buatJurnal(9, 'draft');
        $r = app(GeneralLedgerController::class)->closePeriod($post('/x', ['year' => $TAHUN, 'month' => 9]), $svc);
        $sesi = $r->getSession()?->get('error') ?? '';
        $cek('General Ledger ikut menolak', is_string($sesi) && str_contains($sesi, 'Jurnal Baru'), (string) $sesi);
        $cek('periodenya tidak tertutup', $statusPeriode(9) !== 'closed', (string) $statusPeriode(9));
    }

    echo "\n=== 7. Hitungan per bulan yang dikirim ke layar ===\n";

    $per = $svc->belumApprovedPerBulan($TAHUN);
    $cek('dua belas bulan terisi', count($per) === 12, (string) count($per));
    $cek('bulan 5 = 3 baru, 2 final',
        $per[5]['baru'] === 3 && $per[5]['final'] === 2,
        json_encode($per[5]));
    $cek('bulan 2 yang semuanya Approved dihitung nol',
        $per[2]['baru'] === 0 && $per[2]['final'] === 0, json_encode($per[2]));
    $cek('bulan 1 yang tanpa jurnal dihitung nol',
        $per[1]['baru'] === 0 && $per[1]['final'] === 0, json_encode($per[1]));
} finally {
    DB::rollBack();
}

echo "\n=== 8. Rollback bersih ===\n";
$cek('jurnal uji tidak tertinggal',
    BsJournal::withTrashed()->where('journal_number', 'like', 'UJI/CLOSE/%')->count() === 0);
$cek('periode uji tidak tertinggal',
    BsFiscalPeriod::where('year', $TAHUN)->count() === 0);

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
