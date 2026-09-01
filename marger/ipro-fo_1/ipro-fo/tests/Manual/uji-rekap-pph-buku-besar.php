<?php

declare(strict_types=1);

/*
 * Uji sinkronisasi Rekap PPh Dipotong ↔ akun Hutang PPh di buku besar.
 *
 * YANG DIJAGA — sebuah INVARIAN: setiap baris jurnal pada keempat akun Hutang PPh
 * (20104040401 s/d 20104040404) HARUS muncul di rincian Rekap PPh.
 *
 * Kenapa perlu dijaga uji: versi lama membaca kolom bs_ap_invoices.pph_amount, dan jalur
 * Posting Invoice (komisi/GMC, PO, SPK) memaku kolom itu ke 0 pada SETIAP invoice yang
 * dibuatnya — padahal jurnalnya tetap mengkredit akun Hutang PPh. Akibatnya kartu PPh 23
 * berbunyi "Rp 0" sementara barisnya jelas ada di Detail Transaksi Jurnal, dan tabel
 * rinciannya kosong. Tidak ada galat. Tidak ada tanda. Hanya angka yang salah.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-rekap-pph-buku-besar.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Services\BukuBesarDokumen;
use App\Http\Controllers\Web\BusinessSupport\Tax\PphController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    $cek('user uji berhak membuka Rekap PPh', (bool) $user?->canModule('tax'), 'user '.$userId);

    echo "\n=== 0. Penaut dokumen cocok dengan skema ===\n";

    $bb = app(BukuBesarDokumen::class);
    $cek('semua kolom penaut ada di basis data', $bb->kolomHilang() === [], implode(', ', $bb->kolomHilang()));

    $akun = DB::table('bs_accounts')->whereIn('code', [
        '20104040401', '20104040402', '20104040403', '20104040404',
    ])->pluck('id', 'code');
    $cek('keempat akun Hutang PPh ada di CoA', $akun->count() === 4, (string) $akun->count());

    // ── Fikstur: empat jurnal, satu per pasal, dari jalur yang berbeda-beda ──────────────────
    $akunLawan = (int) DB::table('bs_accounts')->where('is_postable', true)
        ->whereNotIn('id', $akun->values()->all())->orderBy('id')->value('id');
    $sup = DB::table('bs_suppliers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-PPH-SP', 'name' => 'UJI Supplier PPh',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $tgl = '2026-05-12';
    $buat = function (string $nomor, string $kodeAkun, float $pph, string $ket, string $status = 'posted')
    use ($tenantId, $akun, $akunLawan, $userId, $tgl): int {
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenantId, 'journal_number' => $nomor, 'journal_date' => $tgl,
            'period_year' => 2026, 'period_month' => 5, 'source' => 'ap',
            'reference' => 'REF-'.$nomor, 'description' => $ket, 'status' => $status,
            'total_debit' => $pph, 'total_credit' => $pph, 'created_by' => $userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$akunLawan, $pph, 0], [(int) $akun[$kodeAkun], 0, $pph]] as [$acc, $d, $k]) {
            DB::table('bs_journal_lines')->insert([
                'journal_id' => $jid, 'account_id' => $acc, 'description' => $ket,
                'debit' => $d, 'credit' => $k, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $jid;
    };

    // J1 — komisi lewat Posting Invoice: invoicenya ber-pph_amount = 0 (persis seperti yang
    // dibuat PostingInvoiceController), tetapi jurnalnya MENGKREDIT Hutang PPh 23.
    // Inilah baris yang dulu mustahil muncul di rekap.
    $j1 = $buat('UJI-PPH-J1', '20104040403', 236167, 'AP — Komisi, PPh dipotong');
    DB::table('bs_ap_invoices')->insert([
        'tenant_id' => $tenantId, 'invoice_number' => 'UJI-PPH-INV-1', 'invoice_date' => $tgl,
        'due_date' => $tgl, 'supplier_id' => $sup, 'payable_account_id' => $akunLawan,
        'expense_account_id' => $akunLawan, 'description' => 'UJI komisi', 'trx_type' => 'normal',
        'status' => 'open', 'total' => 11808334, 'dpp' => 11808334, 'net_payable' => 11572167,
        'paid_amount' => 0, 'pph_amount' => 0, 'journal_id' => $j1, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // J2 — PPh 21 dari payroll.
    $j2 = $buat('UJI-PPH-J2', '20104040402', 1500000, 'Payroll — PPh 21 dipotong');
    // J3 — PPh final 4(2) lewat jurnal memorial manual, tanpa dokumen sama sekali.
    $j3 = $buat('UJI-PPH-J3', '20104040401', 750000, 'Memorial — PPh final sewa');
    // J4 — PPh 23 pada jurnal yang BELUM approved: harus tampil, tetapi tidak menaikkan SPT.
    $j4 = $buat('UJI-PPH-J4', '20104040403', 99000, 'AP — PPh belum approved', 'draft');

    // J5 — jurnal PPh 25/29 yang SUDAH DIHAPUS. Rincian rekap membuangnya (lewat Eloquent,
    // soft delete berlaku); cross-check GL memakai DB::table yang TIDAK mengenal soft delete,
    // jadi tanpa penjagaan ia tetap menghitungnya dan centang hijau pada kartunya hilang tanpa
    // sebab yang bisa dijelaskan operator. Ini juga satu-satunya kasus di fikstur ini yang
    // membuat cross-check GL benar-benar diuji sebagai query MANDIRI.
    $j5 = $buat('UJI-PPH-J5', '20104040404', 4200000, 'PPh 25/29 — jurnal dibatalkan');
    DB::table('bs_journals')->where('id', $j5)->update(['deleted_at' => now()]);

    $ctl = app(PphController::class);
    $req = Request::create('/x', 'GET', ['year' => 2026, 'month' => 5]);
    $req->setUserResolver(fn () => $user);
    $props = $ctl->index($req)->toResponse($req)->getOriginalContent()->getData()['page']['props'];
    $rows  = collect($props['rows']);

    echo "\n=== 1. INVARIAN: tiap baris jurnal akun Hutang PPh tampil di rekap ===\n";

    // Dihitung MANDIRI dari basis data, bukan dari keluaran layar — kalau keduanya dari
    // sumber yang sama, uji ini tidak membuktikan apa pun.
    $dbJurnal = DB::table('bs_journal_lines as l')
        ->join('bs_journals as j', 'j.id', '=', 'l.journal_id')
        ->whereIn('l.account_id', $akun->values()->all())
        ->whereNull('j.deleted_at')
        ->whereYear('j.journal_date', 2026)->whereMonth('j.journal_date', 5)
        ->pluck('l.journal_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $layarJurnal = $rows->pluck('journal_id')->map(fn ($v) => (int) $v)->sort()->values()->all();

    $cek('jumlah barisnya sama persis', count($dbJurnal) === count($layarJurnal),
        'basis data '.count($dbJurnal).' vs layar '.count($layarJurnal));
    $cek('jurnalnya sama persis', $dbJurnal === $layarJurnal,
        'kurang: '.json_encode(array_values(array_diff($dbJurnal, $layarJurnal))));

    echo "\n=== 2. Baris yang dulu mustahil muncul, kini muncul ===\n";

    $r1 = $rows->firstWhere('journal_number', 'UJI-PPH-J1');
    $cek('komisi ber-pph_amount 0 tetap terbaca', $r1 !== null,
        'baris ini yang dulu hilang: invoicenya pph_amount = 0, jurnalnya mengkredit Hutang PPh');
    $cek('nilainya diambil dari jurnal, bukan dari kolom invoice',
        $r1 !== null && (float) $r1['pph'] === 236167.0, (string) ($r1['pph'] ?? 'null'));
    $cek('pasalnya dikenali dari akunnya', ($r1['article'] ?? '') === 'PPh Pasal 23', (string) ($r1['article'] ?? ''));
    $cek('dokumennya dikenali sebagai Invoice Hutang', ($r1['source'] ?? '') === 'Invoice Hutang', (string) ($r1['source'] ?? ''));
    $cek('nomor invoicenya terbaca', ($r1['doc_no'] ?? '') === 'UJI-PPH-INV-1', (string) ($r1['doc_no'] ?? ''));
    $cek('lawan transaksinya terbaca', str_contains((string) ($r1['partner'] ?? ''), 'UJI Supplier PPh'),
        (string) ($r1['partner'] ?? ''));
    $cek('DPP diambil dari invoicenya', (float) ($r1['base'] ?? 0) === 11808334.0, (string) ($r1['base'] ?? 'null'));

    $r3 = $rows->firstWhere('journal_number', 'UJI-PPH-J3');
    $cek('jurnal memorial tanpa dokumen tetap tampil', $r3 !== null);
    // DPP memang tidak ada untuk jalur ini; menuliskan 0 akan terbaca "DPP-nya nol".
    // Kuncinya harus ADA dan bernilai null. ('?? ' tidak bisa dipakai di sini: ia menyamakan
    // null dengan tidak-ada, dan pemeriksaannya jadi mustahil benar.)
    $cek('DPP dikosongkan, bukan dikarang jadi nol',
        $r3 !== null && array_key_exists('base', $r3) && $r3['base'] === null,
        json_encode($r3['base'] ?? '<kunci tidak ada>'));

    echo "\n=== 3. Angka SPT hanya dari jurnal Approved ===\n";

    $cek('PPh 23 = 236.167 (yang draft tidak ikut)',
        (float) $props['byArticle']['PPh Pasal 23'] === 236167.0, (string) $props['byArticle']['PPh Pasal 23']);
    $cek('yang belum approved disebut terpisah',
        (float) $props['belumApproved']['PPh Pasal 23'] === 99000.0, (string) $props['belumApproved']['PPh Pasal 23']);
    $r4 = $rows->firstWhere('journal_number', 'UJI-PPH-J4');
    $cek('barisnya tetap tampil di rincian', $r4 !== null);
    $cek('ditandai belum efektif', $r4 !== null && ($r4['efektif'] ?? true) === false, json_encode($r4['efektif'] ?? null));
    $cek('PPh 21 terbaca', (float) $props['byArticle']['PPh Pasal 21'] === 1500000.0, (string) $props['byArticle']['PPh Pasal 21']);
    $cek('PPh 4(2) terbaca', (float) $props['byArticle']['PPh Pasal 4(2)'] === 750000.0, (string) $props['byArticle']['PPh Pasal 4(2)']);

    echo "\n=== 4. Kartu pasal cocok dengan buku besar ===\n";

    // Cross-check GL dihitung lewat query TERPISAH di controller.
    //
    // CATATAN JUJUR soal batas uji ini: selama rekapnya benar, angka GL dan angka rekap memang
    // identik — jadi mutasi yang membuat cross-check itu sekadar MENYALIN byArticle tidak bisa
    // ketahuan dari keluarannya. Nilainya baru terasa saat salah satu sisi kelak menyimpang,
    // dan itulah yang tidak bisa diuji hari ini. Yang BISA diuji, dan diuji di bawah: kedua
    // sisi memperlakukan jurnal terhapus dengan cara yang sama.
    foreach (['PPh Pasal 21', 'PPh Pasal 23', 'PPh Pasal 4(2)', 'PPh Pasal 25/29'] as $pasal) {
        $cek("kartu {$pasal} cocok dengan GL",
            abs((float) $props['byArticle'][$pasal] - (float) $props['gl'][$pasal]) < 0.01,
            'rekap='.$props['byArticle'][$pasal].' gl='.$props['gl'][$pasal]);
    }
    $cek('total terutang = jumlah keempat pasal',
        (float) $props['totalTerutang'] === round(array_sum($props['byArticle']), 2),
        (string) $props['totalTerutang']);

    // Jurnal yang sudah dihapus tidak boleh muncul di mana pun — tidak di rincian, tidak di
    // kartu GL. Kalau ia hanya hilang dari salah satunya, centang hijau lenyap tanpa sebab.
    $cek('jurnal yang dihapus tidak muncul di rincian',
        $rows->firstWhere('journal_number', 'UJI-PPH-J5') === null, 'jurnal terhapus ikut tampil');
    $cek('jurnal yang dihapus tidak ikut dihitung GL',
        (float) $props['gl']['PPh Pasal 25/29'] === 0.0, (string) $props['gl']['PPh Pasal 25/29']);

    echo "\n=== 5. Tautan ke Detail Transaksi Jurnal ===\n";

    $cek('keempat akun dikirim ke layar', count($props['akun']) === 4, (string) count($props['akun']));
    $cek('tiap akun membawa id untuk penyaring',
        collect($props['akun'])->every(fn ($a) => ($a['id'] ?? 0) > 0), json_encode($props['akun']));
    // Layar tujuan berbawaan "bulan berjalan"; tanpa rentang masa pajak, tautannya mendarat
    // di masa yang salah dan terbaca sebagai "jurnalnya tidak ada".
    $cek('rentang masa pajak dikirim', ($props['periode']['from'] ?? '') === '2026-05-01'
        && ($props['periode']['to'] ?? '') === '2026-05-31', json_encode($props['periode']));
    $cek('tiap baris membawa journal_id untuk ditaut',
        $rows->every(fn ($r) => ($r['journal_id'] ?? 0) > 0), 'ada baris tanpa journal_id');

    echo "\n=== 6. Masa pajak lain tidak bocor ===\n";

    $reqLain = Request::create('/x', 'GET', ['year' => 2026, 'month' => 4]);
    $reqLain->setUserResolver(fn () => $user);
    $lain = collect($ctl->index($reqLain)->toResponse($reqLain)->getOriginalContent()->getData()['page']['props']['rows']);
    $cek('jurnal Mei tidak muncul di masa April',
        $lain->filter(fn ($r) => str_starts_with((string) $r['journal_number'], 'UJI-PPH-'))->isEmpty(),
        (string) $lain->filter(fn ($r) => str_starts_with((string) $r['journal_number'], 'UJI-PPH-'))->count());

    echo "\n=== 7. Ekspor CSV memakai hitungan yang sama ===\n";

    $csv = '';
    $resp = $ctl->export($req);
    ob_start();
    $resp->sendContent();
    $csv = (string) ob_get_clean();

    $cek('CSV memuat baris komisi yang dulu hilang', str_contains($csv, 'UJI-PPH-INV-1'), 'tidak ditemukan');
    $cek('CSV memuat nomor jurnalnya', str_contains($csv, 'UJI-PPH-J1'), 'tidak ditemukan');
    $cek('CSV memuat nilai PPh-nya', str_contains($csv, '236167'), 'tidak ditemukan');
    $cek('CSV ikut menyebut status jurnalnya', str_contains($csv, 'Baru') || str_contains($csv, 'Approved'), 'tidak ditemukan');
    $cek('jumlah baris CSV = jumlah baris layar',
        substr_count(trim($csv), "\n") === $rows->count(), // header + N baris → N newline
        substr_count(trim($csv), "\n").' vs '.$rows->count());

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = DB::table('bs_journals')->where('journal_number', 'like', 'UJI-PPH-%')->count()
        + DB::table('bs_suppliers')->where('code', 'like', 'UJI-PPH-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
