<?php

declare(strict_types=1);

/*
 * Uji sinkronisasi UM Penjualan ↔ buku besar akun Uang Muka Penjualan.
 *
 * YANG DIJAGA — sebuah INVARIAN, bukan sekadar beberapa contoh:
 *   setiap baris jurnal pada akun Uang Muka Penjualan HARUS tampil di layar UM Penjualan.
 *
 * Kenapa itu perlu dijaga oleh uji, bukan oleh mata: kalau sebuah baris buku besar tidak
 * ikut terbawa, layarnya TIDAK error. Ia hanya menampilkan satu baris lebih sedikit, dan
 * satu-satunya cara menyadarinya adalah menghitung ulang buku besar dengan tangan. Justru
 * itulah keluhan yang memulai pekerjaan ini.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — basis data kembali seperti semula.
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-um-penjualan-buku-besar.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Services\UangMukaPenjualanLedger;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArUmPenjualanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// tenant_id() diisi middleware SetTenant; di luar request ia NULL dan TenantScope menyaring
// habis seluruh baris — nol baris yang terbaca seolah "memang tidak ada datanya".
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
    $ledger = app(UangMukaPenjualanLedger::class);

    echo "\n=== 0. Penaut dokumen cocok dengan skema ===\n";

    // Penelusuran balik MEMAAFKAN kolom yang tidak ada supaya satu tabel yang belum dimigrasi
    // tidak menjatuhkan seluruh layar. Sifat itu juga memaafkan salah ketik — penautnya mati
    // diam-diam, baris tetap tampil, hanya kehilangan nomor dokumen & customernya.
    $cek('semua kolom penaut ada di basis data', $ledger->kolomHilang() === [],
        implode(', ', $ledger->kolomHilang()));

    $akun = $ledger->accounts();
    $cek('akun Uang Muka Penjualan ketemu', $akun->isNotEmpty(),
        'tidak ada akun postable bernama "uang muka penjualan" maupun kode di config');

    $akunUmId = (int) $akun->first()->id;

    // ── Fikstur ────────────────────────────────────────────────────────────────────────────
    $projectId = (int) DB::table('projects')->orderBy('id')->value('id');
    $skemaId   = (int) DB::table('payment_schemes')->orderBy('id')->value('id');
    $bankId    = (int) DB::table('bank_partners')->orderBy('id')->value('id');
    $unitIds   = DB::table('units')->orderBy('id')->limit(3)->pluck('id')->all();
    $userId    = (int) DB::table('users')->orderBy('id')->value('id');
    $akunLain  = (int) DB::table('bs_accounts')->where('is_postable', true)
        ->where('id', '!=', $akunUmId)->orderBy('id')->value('id');
    $tgl = '2026-03-15';

    $custId = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-UM-C1', 'full_name' => 'UJI Pelanggan Uang Muka',
        'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = (int) DB::table('bookings')->insertGetId([
        'tenant_id' => $tenantId, 'project_id' => $projectId, 'booking_number' => 'UJI-UM-BK1',
        'customer_id' => $custId, 'unit_id' => $unitIds[0], 'payment_scheme_id' => $skemaId,
        'booking_date' => $tgl, 'base_price' => 500000000, 'total_price' => 500000000,
        'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    /** Satu jurnal + barisnya. $um = nilai pada akun UM (positif = kredit, negatif = debit, 0 = tidak menyentuh). */
    $buatJurnal = function (string $nomor, float $um, string $ket, string $status = 'posted')
    use ($tenantId, $akunUmId, $akunLain, $userId, $tgl): int {
        $nilai = abs($um) ?: 1000.0;
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenantId, 'journal_number' => $nomor, 'journal_date' => $tgl,
            'period_year' => 2026, 'period_month' => 3, 'source' => 'memorial',
            'reference' => 'UJI-REF-'.$nomor, 'description' => $ket, 'status' => $status,
            'total_debit' => $nilai, 'total_credit' => $nilai, 'created_by' => $userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Lawan-baris SELALU di akun lain, jadi jurnalnya seimbang tanpa menambah baris UM
        // yang tidak dimaksudkan — kalau tidak, hitungan invarian di bawah ikut tercemar.
        $baris = $um > 0
            ? [[$akunLain, $nilai, 0], [$akunUmId, 0, $nilai]]
            : ($um < 0
                ? [[$akunUmId, $nilai, 0], [$akunLain, 0, $nilai]]
                : [[$akunLain, $nilai, 0], [$akunLain, 0, $nilai]]);

        foreach ($baris as [$acc, $d, $k]) {
            DB::table('bs_journal_lines')->insert([
                'journal_id' => $jid, 'account_id' => $acc, 'description' => $ket,
                'debit' => $d, 'credit' => $k, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $jid;
    };

    // J1 — konversi UTJ/DP: Cr Uang Muka 100.000.000. PUNYA wakil di antrean.
    $j1 = $buatJurnal('UJI-UM-J1', 100000000, 'Konversi UTJ/DP ke Uang Muka Penjualan');
    $entryId = (int) DB::table('bs_faktur_keluaran_entries')->insertGetId([
        'tenant_id' => $tenantId, 'source_type' => 'utj_dp', 'source_id' => 1,
        'customer_id' => $custId, 'booking_id' => $bookingId, 'doc_date' => $tgl,
        'dpp' => 100000000, 'ppn' => 11000000, 'total' => 111000000, 'is_active' => 1,
        'keterangan' => 'UJI entri UTJ/DP', 'converted_at' => now(), 'convert_journal_id' => $j1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // J2 — Booked Revenue: Cr Uang Muka 500.000.000. TIDAK punya wakil di antrean.
    $j2 = $buatJurnal('UJI-UM-J2', 500000000, 'Booked Revenue — pendapatan diterima dimuka');
    DB::table('bs_booked_revenues')->insert([
        'tenant_id' => $tenantId, 'br_number' => 'UJI-BR-001', 'booking_date' => $tgl,
        'customer_id' => $custId, 'unit_id' => $unitIds[0], 'project_id' => $projectId,
        'amount' => 500000000, 'gross_price' => 555000000, 'discount' => 0,
        'receivable_account_id' => $akunLain, 'deferred_account_id' => $akunUmId,
        'income_account_id' => $akunLain, 'status' => 'booked',
        'booking_journal_id' => $j2, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // J3 — jurnal memorial tanpa dokumen: Dr Uang Muka 200.000.000 (mengurangi saldo).
    $j3 = $buatJurnal('UJI-UM-J3', -200000000, 'Koreksi manual uang muka');

    // J4 — pencairan KPR yang kreditnya ke Piutang Usaha: TIDAK menyentuh akun UM sama sekali,
    // tetapi TETAP punya baris di antrean. Inilah selisih yang wajar itu.
    $j4 = $buatJurnal('UJI-UM-J4', 0, 'Pencairan KPR — pelunasan Piutang Usaha');
    $appId = (int) DB::table('kpr_applications')->insertGetId([
        'tenant_id' => $tenantId, 'application_number' => 'UJI-UM-KPR1', 'booking_id' => $bookingId,
        'customer_id' => $custId, 'bank_partner_id' => $bankId, 'plafon_requested' => 400000000,
        'submitted_at' => $tgl, 'status' => 'disbursed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $realId = (int) DB::table('kpr_realizations')->insertGetId([
        'kpr_application_id' => $appId, 'disbursed_at' => $tgl, 'disbursed_amount' => 400000000,
        'retention_amount' => 0, 'ssp_bphtb_amount' => 0, 'journal_id' => $j4,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // J5 — entri UTJ/DP tahap-1 yang akun kreditnya kebetulan dipilih akun Uang Muka, TAPI
    // belum pernah dikonversi (converted_at NULL). Jurnalnya nyata ada di akun UM, sedangkan
    // barisnya TIDAK pernah masuk antrean. Menandainya "ada di antrean" adalah kebohongan yang
    // membuat operator mencarinya di tab Antrean dan tidak menemukannya.
    $j5 = $buatJurnal('UJI-UM-J5', 25000000, 'UTJ tahap-1 dikreditkan ke Uang Muka');
    DB::table('bs_faktur_keluaran_entries')->insert([
        'tenant_id' => $tenantId, 'source_type' => 'utj_dp', 'source_id' => 2,
        'customer_id' => $custId, 'booking_id' => $bookingId, 'doc_date' => $tgl,
        'dpp' => 25000000, 'ppn' => 0, 'total' => 25000000, 'is_active' => 1,
        'keterangan' => 'UJI entri belum dikonversi', 'converted_at' => null, 'journal_id' => $j5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // J6 — pelepasan retensi DAJAM per tranche. Memposting ke akun UM tetapi BUKAN salah satu
    // dari tiga sumber antrean, jadi tanpa penaut ia selalu terbaca "jurnal manual tak terlacak".
    $j6 = $buatJurnal('UJI-UM-J6', 30000000, 'Pelepasan retensi DAJAM tranche 1');
    $retId = (int) DB::table('kpr_retentions')->insertGetId([
        'kpr_realization_id' => $realId, 'amount' => 30000000, 'status' => 'released',
        'released_amount' => 30000000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('kpr_retention_releases')->insert([
        'kpr_retention_id' => $retId, 'amount' => 30000000, 'released_at' => $tgl,
        'journal_id' => $j6, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // J7 — pencairan yang SELURUHNYA tertahan retensi (cair − retensi = 0). Antrean membuang
    // baris seperti ini (syaratnya "> 0.009"), jadi klaimnya pun harus ikut membuang. Kalau
    // saringannya lebih longgar, baris buku besar ini ditandai "ada di antrean" padahal
    // antreannya tidak memuatnya.
    $j7 = $buatJurnal('UJI-UM-J7', 15000000, 'Pencairan yang seluruhnya tertahan retensi');
    DB::table('kpr_realizations')->insert([
        'kpr_application_id' => $appId, 'disbursed_at' => $tgl, 'disbursed_amount' => 15000000,
        'retention_amount' => 15000000, 'ssp_bphtb_amount' => 0, 'journal_id' => $j7,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // ── Panggil layarnya seperti pengguna sungguhan ─────────────────────────────────────────
    $user = \App\Models\User::query()->find($userId);
    $req  = new Request;
    $req->setUserResolver(fn () => $user);
    $cek('user uji berhak membuka layar ini', (bool) $user?->canModule('ar'),
        'user '.$userId.' tidak punya modul ar');

    $resp  = app(ArUmPenjualanController::class)->index($req, $ledger);
    $props = $resp->toResponse($req)->getOriginalContent()->getData()['page']['props'];

    $glRows = collect($props['gl']['rows']);
    $ujiGl  = $glRows->filter(fn ($r) => str_starts_with((string) $r['journal_number'], 'UJI-UM-'))->values();
    $antre  = collect($props['rows']);
    $rekon  = $props['rekon'];

    echo "\n=== 1. INVARIAN: tiap baris buku besar akun UM tampil di layar ===\n";

    // Dihitung dari basis data secara mandiri, BUKAN dari keluaran layar — kalau keduanya
    // dihitung dari sumber yang sama, uji ini tidak membuktikan apa pun.
    $dbLines = DB::table('bs_journal_lines')->where('account_id', $akunUmId)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    $layar   = $glRows->pluck('line_id')->map(fn ($v) => (int) $v)->sort()->values()->all();

    $cek('jumlah barisnya sama persis', count($dbLines) === count($layar),
        'basis data '.count($dbLines).' vs layar '.count($layar));
    $cek('id barisnya sama persis', $dbLines === $layar,
        'kurang: '.json_encode(array_values(array_diff($dbLines, $layar))));
    $cek('keenam baris uji ikut terbawa', $ujiGl->count() === 6, (string) $ujiGl->count());

    echo "\n=== 2. Jalur yang selama ini tak terwakili kini terlihat ===\n";

    $r2 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J2');
    $cek('baris Booked Revenue muncul', $r2 !== null);
    $cek('dokumennya dikenali sebagai Booked Revenue', ($r2['doc_label'] ?? null) === 'Booked Revenue', (string) ($r2['doc_label'] ?? 'null'));
    $cek('nomor BR-nya terbaca', ($r2['doc_number'] ?? null) === 'UJI-BR-001', (string) ($r2['doc_number'] ?? 'null'));
    $cek('customernya terbaca', ($r2['customer'] ?? null) === 'UJI Pelanggan Uang Muka', (string) ($r2['customer'] ?? 'null'));
    // Kuncinya harus ADA dan bernilai null — bukan sekadar tidak ada. Layar membedakan
    // "sudah punya wakil di antrean" dari "belum", dan kunci yang hilang membuat keduanya
    // tak terbedakan. (Catatan: '?? ' tidak bisa dipakai di sini, ia menyamakan null
    // dengan tidak-ada — itulah yang membuat pemeriksaan ini sempat merah padahal benar.)
    $cek('ditandai tidak punya wakil di antrean',
        array_key_exists('queue_key', $r2) && $r2['queue_key'] === null,
        json_encode($r2['queue_key'] ?? '<kunci tidak ada>'));

    $r3 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J3');
    $cek('jurnal memorial manual muncul juga', $r3 !== null);
    $cek('memorial manual tidak dikarang dokumennya', ($r3['doc_label'] ?? null) === null, (string) ($r3['doc_label'] ?? 'null'));
    $cek('arahnya terbaca sebagai debit', ($r3['debit'] ?? 0) == 200000000 && ($r3['credit'] ?? 0) == 0,
        'D='.($r3['debit'] ?? '?').' K='.($r3['credit'] ?? '?'));
    $cek('nettonya negatif — mengurangi saldo UM', ($r3['net'] ?? 0) == -200000000, (string) ($r3['net'] ?? '?'));

    echo "\n=== 3. Tidak ada yang terhitung dua kali ===\n";

    $r1 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J1');
    $cek('baris UTJ/DP ditandai sudah ada di antrean',
        ($r1['queue_key'] ?? null) === 'utj_dp-'.$entryId, (string) ($r1['queue_key'] ?? 'null'));
    // Sebuah dokumen yang sudah punya baris antrean TIDAK boleh juga terhitung sebagai baris
    // baru — kalau itu terjadi, satu penerimaan tampil dua kali dan totalnya berlipat.
    $cek('barisnya tidak digandakan',
        $ujiGl->where('journal_number', 'UJI-UM-J1')->count() === 1,
        (string) $ujiGl->where('journal_number', 'UJI-UM-J1')->count());

    echo "\n=== 3b. Tanda \"ada di antrean\" tidak berbohong ===\n";

    $r5 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J5');
    $cek('jurnal UTJ tahap-1 tetap tampil di buku besar', $r5 !== null);
    // Saringan klaim harus SAMA PERSIS dengan yang membentuk antrean. Yang lebih longgar
    // akan menandai baris ini "ada di antrean" padahal entrinya belum dikonversi.
    $cek('entri yang belum dikonversi TIDAK ditandai ada di antrean',
        $r5 !== null && array_key_exists('queue_key', $r5) && $r5['queue_key'] === null,
        json_encode($r5['queue_key'] ?? '<kunci tidak ada>'));
    $cek('tidak ada baris antrean untuk entri itu',
        $antre->filter(fn ($a) => str_starts_with((string) $a['row_key'], 'utj_dp-'))->count() === 1,
        (string) $antre->filter(fn ($a) => str_starts_with((string) $a['row_key'], 'utj_dp-'))->count());

    $r6 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J6');
    $cek('pelepasan retensi DAJAM tampil di buku besar', $r6 !== null);
    $cek('dokumennya dikenali, bukan dibiarkan tak terlacak',
        ($r6['doc_label'] ?? null) === 'Pelepasan Retensi DAJAM', (string) ($r6['doc_label'] ?? 'null'));
    $r7 = $ujiGl->firstWhere('journal_number', 'UJI-UM-J7');
    $cek('pencairan yang habis tertahan tetap tampil di buku besar', $r7 !== null);
    $cek('pencairan yang dibuang antrean TIDAK ditandai ada di antrean',
        $r7 !== null && array_key_exists('queue_key', $r7) && $r7['queue_key'] === null,
        json_encode($r7['queue_key'] ?? '<kunci tidak ada>'));

    $cek('pelepasan retensi tidak punya wakil di antrean',
        $r6 !== null && array_key_exists('queue_key', $r6) && $r6['queue_key'] === null,
        json_encode($r6['queue_key'] ?? '<kunci tidak ada>'));

    echo "\n=== 4. Baris antrean tahu nilainya di buku besar ===\n";

    $aUtj = $antre->firstWhere('row_key', 'utj_dp-'.$entryId);
    $cek('baris UTJ/DP ada di antrean', $aUtj !== null);
    $cek('membawa journal_id sehingga nomornya bisa ditaut',
        ($aUtj['journal_id'] ?? null) === $j1, json_encode($aUtj['journal_id'] ?? null));
    $cek('tahu ia ada di buku besar', ($aUtj['gl_ada'] ?? false) === true);
    // Nilai antrean 111 juta (termasuk PPN) tetapi yang masuk akun UM hanya 100 juta.
    // Perbedaan itu WAJAR dan justru harus terbaca, bukan disamarkan.
    $cek('nilai buku besarnya DPP saja, bukan total', (float) ($aUtj['gl_net'] ?? 0) == 100000000.0,
        (string) ($aUtj['gl_net'] ?? 'null'));
    $cek('nilai antreannya tetap total termasuk PPN', (float) ($aUtj['total'] ?? 0) == 111000000.0,
        (string) ($aUtj['total'] ?? 'null'));

    $aPenc = $antre->firstWhere('row_key', 'pencairan-'.$realId);
    $cek('baris pencairan ada di antrean', $aPenc !== null);
    $cek('ditandai TIDAK menyentuh akun UM', ($aPenc['gl_ada'] ?? true) === false,
        json_encode($aPenc['gl_ada'] ?? null));
    $cek('tetap membawa journal_id-nya sendiri', ($aPenc['journal_id'] ?? null) === $j4,
        json_encode($aPenc['journal_id'] ?? null));

    echo "\n=== 5. Jembatan selisih benar-benar menjumlah ===\n";

    $kredit = round((float) $glRows->sum('credit'), 2);
    $debit  = round((float) $glRows->sum('debit'), 2);
    $cek('kredit rekonsiliasi = jumlah kolom kredit', (float) $rekon['gl_kredit'] === $kredit,
        $rekon['gl_kredit'].' vs '.$kredit);
    $cek('debit rekonsiliasi = jumlah kolom debit', (float) $rekon['gl_debit'] === $debit,
        $rekon['gl_debit'].' vs '.$debit);
    $cek('saldo = kredit − debit', (float) $rekon['gl_saldo'] === round($kredit - $debit, 2),
        $rekon['gl_saldo'].' vs '.round($kredit - $debit, 2));
    $cek('total antrean = jumlah kolom total', (float) $rekon['antrean_total'] === round((float) $antre->sum('total'), 2),
        $rekon['antrean_total'].' vs '.round((float) $antre->sum('total'), 2));
    $cek('baris antrean tanpa jejak di buku besar terhitung', (int) $rekon['tanpa_gl_count'] >= 1,
        (string) $rekon['tanpa_gl_count']);
    $cek('baris buku besar tanpa wakil terhitung', (int) $rekon['yatim_count'] >= 2,
        (string) $rekon['yatim_count']);
    // J3 (−200 jt) ada di antara yang yatim; kalau tandanya terbalik angkanya jadi +200 jt.
    $cek('netto yatim ikut menghitung yang bertanda minus',
        (float) $rekon['yatim_net'] <= 500000000.0,
        (string) $rekon['yatim_net'].' — J3 yang −200 jt tampaknya tidak ikut dikurangkan');

    echo "\n=== 6. Rentang tanggal untuk tautan ke Detail Transaksi Jurnal ===\n";

    // Layar tujuan berbawaan "bulan berjalan". Tanpa rentang nyata, tautannya mendarat kosong
    // untuk baris di luar bulan ini — dan itu terbaca sebagai "jurnalnya tidak ada".
    $per = $props['gl']['periode'];
    $cek('rentang awal terisi dari data', ! empty($per['from']), json_encode($per));
    $cek('rentang akhir terisi dari data', ! empty($per['to']), json_encode($per));
    $cek('rentangnya mencakup tanggal jurnal uji',
        $per['from'] <= $tgl && $per['to'] >= $tgl, json_encode($per).' vs '.$tgl);
    $cek('akun UM dikirim ke layar untuk dijadikan penyaring',
        collect($props['gl']['accounts'])->contains('id', $akunUmId), json_encode($props['gl']['accounts']));

    echo "\n=== 7. Penyaringan tanggal tidak membocorkan baris ===\n";

    $req2 = new Request(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    $req2->setUserResolver(fn () => $user);
    $props2 = app(ArUmPenjualanController::class)->index($req2, $ledger)
        ->toResponse($req2)->getOriginalContent()->getData()['page']['props'];
    $bocor = collect($props2['gl']['rows'])->filter(fn ($r) => str_starts_with((string) $r['journal_number'], 'UJI-UM-'));
    $cek('jurnal Maret tidak muncul saat menyaring Januari', $bocor->isEmpty(), (string) $bocor->count());

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = DB::table('bs_journals')->where('journal_number', 'like', 'UJI-UM-%')->count()
        + DB::table('customers')->where('code', 'like', 'UJI-UM-%')->count()
        + DB::table('bs_booked_revenues')->where('br_number', 'like', 'UJI-BR-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
