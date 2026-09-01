<?php

declare(strict_types=1);

/*
 * Uji: SETIAP grafik Dashboard Back Office mengikuti periode awal & akhir.
 *
 * CARA MENGUJINYA — invarian "masa lampau harus kosong":
 * data uji ditaruh di SATU bulan, lalu dashboard diminta untuk periode BERTAHUN-TAHUN
 * SEBELUM bulan itu. Grafik yang benar-benar menyaring akan kosong; grafik yang mengabaikan
 * periodenya akan menampilkan isi yang sama persis di kedua periode.
 *
 * Kenapa harus uji, bukan dilihat saja: grafik yang mengabaikan periode TIDAK error. Ia
 * menampilkan angka yang masuk akal — angka seluruh data — dan satu-satunya cara menyadarinya
 * adalah membandingkan dua periode berdampingan sambil menghitung sendiri. Itulah yang membuat
 * dua kartu (Rekonsiliasi Piutang & Summary HPP) selama ini diam-diam tidak tersaring.
 *
 * Grafik yang belum punya fikstur DILAPORKAN sebagai "belum teruji", bukan dihitung lulus —
 * kalau tidak, uji ini akan hijau justru karena tidak menguji apa-apa.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-dashboard-periode-tiap-grafik.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Web\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$lewat = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};
$lewati = function (string $nama, string $sebab) use (&$lewat) {
    $lewat++;
    echo "  LEWAT {$nama} — {$sebab}\n";
};

// Bulan data uji, dan periode "masa lampau" yang mustahil memuatnya.
const BULAN_DATA = '2026-05';
const LAMPAU_DARI = '2019-01-01';
const LAMPAU_SAMPAI = '2019-03-31';

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    $akun   = (int) DB::table('bs_accounts')->where('is_postable', true)->orderBy('id')->value('id');
    $proj   = (int) DB::table('projects')->orderBy('id')->value('id');
    $unit   = (int) DB::table('units')->orderBy('id')->value('id');
    $cc     = (int) DB::table('bs_cost_centers')->orderBy('id')->value('id');
    $tgl    = BULAN_DATA.'-15';
    $now    = now();

    $cek('user uji berhak membuka dashboard', $user !== null, 'user '.$userId.' tidak ada');

    // ── Fikstur: satu baris per grafik, semuanya di BULAN_DATA ───────────────────────────────
    $adaFikstur = [];   // kunci grafik => true bila datanya berhasil dibuat

    $cust = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-DB-C1', 'full_name' => 'UJI Customer Dashboard',
        'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $sup = (int) DB::table('bs_suppliers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-DB-SP', 'name' => 'UJI Supplier Dashboard',
        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $booking = (int) DB::table('bookings')->insertGetId([
        'tenant_id' => $tenantId, 'project_id' => $proj, 'booking_number' => 'UJI-DB-BK',
        'customer_id' => $cust, 'unit_id' => $unit,
        'payment_scheme_id' => (int) DB::table('payment_schemes')->orderBy('id')->value('id'),
        'booking_date' => $tgl, 'base_price' => 500000000, 'total_price' => 500000000,
        'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
    ]);

    /** Buat satu baris; bila tabelnya/kolomnya belum ada, grafiknya ditandai tak terfikstur. */
    $buat = function (string $grafik, string $tabel, array $data) use (&$adaFikstur): void {
        if (! Schema::hasTable($tabel)) {
            return;
        }
        foreach (array_keys($data) as $k) {
            if (! Schema::hasColumn($tabel, $k)) {
                return;
            }
        }
        DB::table($tabel)->insert($data);
        $adaFikstur[$grafik] = true;
    };

    // 1 · Inisiasi KPR
    $buat('inisiasi_kpr', 'kpr_initiations', [
        'tenant_id' => $tenantId, 'customer_id' => $cust, 'booking_id' => $booking,
        'created_at' => $tgl.' 09:00:00', 'updated_at' => $now,
    ]);

    // 2 · Proses KPR
    $bank = (int) DB::table('bank_partners')->orderBy('id')->value('id');
    if (Schema::hasTable('kpr_applications')) {
        DB::table('kpr_applications')->insert([
            'tenant_id' => $tenantId, 'application_number' => 'UJI-DB-KPR', 'booking_id' => $booking,
            'customer_id' => $cust, 'bank_partner_id' => $bank, 'plafon_requested' => 400000000,
            'submitted_at' => $tgl, 'status' => 'submitted', 'created_at' => $tgl.' 09:00:00', 'updated_at' => $now,
        ]);
        $adaFikstur['proses_kpr'] = true;
    }

    // 4 · Saldo Rekening & 7 · Neraca & 8 · Laba Rugi — semuanya dari jurnal.
    $jid = (int) DB::table('bs_journals')->insertGetId([
        'tenant_id' => $tenantId, 'journal_number' => 'UJI-DB-J1', 'journal_date' => $tgl,
        'period_year' => 2026, 'period_month' => 5, 'source' => 'memorial',
        'description' => 'UJI dashboard', 'status' => 'posted',
        'total_debit' => 1000000, 'total_credit' => 1000000, 'created_by' => $userId,
        'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $akunKas = (int) DB::table('bs_accounts')->where('code', 'like', '10101%')->where('is_postable', true)->value('id');
    $akunLaw = (int) DB::table('bs_accounts')->where('is_postable', true)->where('id', '!=', $akunKas)->orderBy('id')->value('id');
    foreach ([[$akunKas ?: $akun, 1000000, 0], [$akunLaw, 0, 1000000]] as [$a, $d, $k]) {
        DB::table('bs_journal_lines')->insert([
            'journal_id' => $jid, 'account_id' => $a, 'description' => 'UJI', 'debit' => $d, 'credit' => $k,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $adaFikstur['rekening'] = true;
    $adaFikstur['neraca'] = true;
    $adaFikstur['laba_rugi'] = true;

    // 5 · Bon Sementara
    $buat('kasbon', 'bs_cash_advances', [
        'tenant_id' => $tenantId, 'advance_number' => 'UJI-DB-BON', 'advance_date' => $tgl,
        'holder' => 'UJI Pemegang', 'purpose' => 'UJI keperluan', 'advance_account_id' => $akun,
        'amount' => 2500000, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // 6 · Aging Hutang
    $buat('aging', 'bs_ap_invoices', [
        'tenant_id' => $tenantId, 'invoice_number' => 'UJI-DB-AP', 'invoice_date' => $tgl,
        'due_date' => $tgl, 'supplier_id' => $sup, 'payable_account_id' => $akun,
        'expense_account_id' => $akun, 'description' => 'UJI hutang', 'trx_type' => 'normal',
        'status' => 'open', 'total' => 7500000, 'net_payable' => 7500000, 'paid_amount' => 0,
        'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // 9 · Aset Tetap
    $buat('aset_tetap', 'bs_fixed_assets', [
        'tenant_id' => $tenantId, 'asset_number' => 'UJI-DB-AST', 'name' => 'UJI Aset',
        'category' => 'kendaraan', 'acquisition_date' => $tgl, 'acquisition_cost' => 90000000,
        'asset_account_id' => $akun, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // 11 · SPK per Cluster  &  12 · Progres  &  13 · Retensi
    $spk = null;
    if (Schema::hasTable('bs_work_orders')) {
        $spk = (int) DB::table('bs_work_orders')->insertGetId([
            'tenant_id' => $tenantId, 'spk_number' => 'UJI-DB-SPK', 'spk_date' => $tgl,
            'supplier_id' => $sup, 'construction_account_id' => $akun, 'title' => 'UJI Pekerjaan',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adaFikstur['spk'] = true;
    }
    if ($spk !== null && Schema::hasTable('bs_work_order_units')) {
        DB::table('bs_work_order_units')->insert([
            'tenant_id' => $tenantId, 'work_order_id' => $spk, 'unit_id' => $unit,
            'unit_code' => 'UJI-DB-UNIT', 'unit_price' => 250000000, 'seq' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adaFikstur['progress'] = true;
    }
    if ($spk !== null && Schema::hasTable('bs_work_order_progress')) {
        DB::table('bs_work_order_progress')->insert([
            'work_order_id' => $spk, 'progress_date' => $tgl, 'description' => 'UJI progres',
            'progress_value' => 5000000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $adaFikstur['progress'] = true;
        $adaFikstur['retensi'] = true;
    }

    // 3 · Penolakan — pengajuan KPR yang ditolak pada bulan data.
    if (Schema::hasTable('kpr_applications')) {
        DB::table('kpr_applications')->insert([
            'tenant_id' => $tenantId, 'application_number' => 'UJI-DB-KPR-TOLAK', 'booking_id' => $booking,
            'customer_id' => $cust, 'bank_partner_id' => $bank, 'plafon_requested' => 300000000,
            'submitted_at' => $tgl, 'status' => 'rejected', 'decided_at' => $tgl.' 10:00:00',
            'created_at' => $tgl.' 09:00:00', 'updated_at' => $now,
        ]);
        $adaFikstur['penolakan'] = true;
    }

    // 10 · Realisasi vs Budget — plafon tahunan + jurnal realisasi pada bulan data.
    if (Schema::hasTable('bs_budgets') && $cc > 0) {
        DB::table('bs_budgets')->insert(array_filter([
            'tenant_id' => $tenantId, 'year' => 2026, 'cost_center_id' => $cc,
            'account_id' => $akunLaw, 'amount' => 50000000,
            'status' => Schema::hasColumn('bs_budgets', 'status') ? 'approved' : null,
            'created_at' => $now, 'updated_at' => $now,
        ], fn ($v) => $v !== null));
        DB::table('bs_journal_lines')->insert([
            'journal_id' => $jid, 'account_id' => $akunLaw, 'cost_center_id' => $cc,
            // Realisasi anggaran dihitung debit − kredit untuk akun beban, lalu diklem ke 0;
            // mengkreditnya membuat pemakaiannya terbaca nol dan ujinya tidak menguji apa pun.
            'description' => 'UJI realisasi budget', 'debit' => 4000000, 'credit' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $adaFikstur['budget'] = true;
    }

    // 14–16 · BAST / AJB / Booked Revenue
    $buat('bast_ajb_br', 'bast_documents', [
        'tenant_id' => $tenantId, 'booking_id' => $booking, 'bast_number' => 'UJI-DB-BAST',
        'handed_over_at' => $tgl, 'created_at' => $now, 'updated_at' => $now,
    ]);

    // 17 · Rekonsiliasi Piutang — inilah salah satu yang dulu tidak tersaring.
    $buat('piutang', 'bs_ar_invoices', [
        'tenant_id' => $tenantId, 'invoice_number' => 'UJI-DB-AR', 'invoice_date' => $tgl,
        'due_date' => $tgl, 'customer_id' => $cust, 'booking_id' => $booking,
        'description' => 'UJI piutang', 'total' => 12000000, 'net_receivable' => 12000000,
        'paid_amount' => 2000000, 'status' => 'open',
        'receivable_account_id' => $akun, 'income_account_id' => $akun,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // 18 · Summary HPP — yang satunya lagi.
    $buat('hpp', 'bs_cogs_hpp', [
        'period_year' => 2026, 'period_month' => 5, 'project_id' => $proj, 'unit_id' => $unit,
        'hpp_tanah' => 100000000, 'hpp_bangunan' => 250000000, 'hpp_permit' => 15000000,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // ── Panggil dashboard untuk dua periode ──────────────────────────────────────────────────
    $render = function (string $dari, string $sampai) use ($user) {
        $r = Request::create('/x', 'GET', ['date_from' => $dari, 'date_to' => $sampai]);
        $r->setUserResolver(fn () => $user);

        return app(DashboardController::class)($r)->toResponse($r)->getOriginalContent()->getData()['page']['props'];
    };

    $kini   = $render(BULAN_DATA.'-01', BULAN_DATA.'-31');
    $lampau = $render(LAMPAU_DARI, LAMPAU_SAMPAI);

    echo "\n=== 1. Dashboard tetap utuh di kedua periode ===\n";

    $cek('periode data memulangkan seluruh grafik', count($kini['charts']) === 16, (string) count($kini['charts']));
    $cek('periode lampau juga memulangkan seluruh grafik', count($lampau['charts']) === 16, (string) count($lampau['charts']));
    $cek('label periodenya ikut berubah',
        ($kini['periode']['label'] ?? '') !== ($lampau['periode']['label'] ?? ''),
        (string) ($kini['periode']['label'] ?? ''));

    echo "\n=== 2. INVARIAN: tiap grafik berubah saat periodenya berubah ===\n";

    // Nama yang dikenali operator, supaya kegagalannya bisa langsung ditunjuk di layar.
    $judul = [
        'inisiasi_kpr' => '1 · Inisiasi KPR',
        'proses_kpr'   => '2 · Proses KPR',
        'penolakan'    => '3 · Penolakan BI Checking & Proses KPR',
        'rekening'     => '4 · Summary Rekening Ledger',
        'kasbon'       => '5 · Bon Sementara',
        'aging'        => '6 · Aging Hutang',
        'neraca'       => '7 · Summary Neraca',
        'laba_rugi'    => '8 · Summary Laba Rugi',
        'aset_tetap'   => '9 · Overview Aset Tetap',
        'budget'       => '10 · Realisasi vs Budget',
        'spk'          => '11 · SPK per Cluster',
        'progress'     => '12 · Progres Lapangan',
        'retensi'      => '13 · Summary Retensi',
        'bast_ajb_br'  => '14–16 · BAST / AJB / Booked Revenue',
        'piutang'      => '17 · Rekonsiliasi Piutang',
        'hpp'          => '18 · Summary HPP',
    ];

    foreach ($judul as $kunci => $nama) {
        if (! array_key_exists($kunci, $kini['charts'])) {
            $lewati($nama, 'kunci grafiknya tidak ada di keluaran dashboard');

            continue;
        }
        if (! isset($adaFikstur[$kunci])) {
            // Dilaporkan, TIDAK dihitung lulus: grafik tanpa data uji akan kosong di kedua
            // periode, dan menghitungnya lulus berarti uji ini hijau karena tidak menguji.
            $lewati($nama, 'belum ada fikstur — tidak bisa dibuktikan menyaring');

            continue;
        }

        $a = json_encode($kini['charts'][$kunci]);
        $b = json_encode($lampau['charts'][$kunci]);
        $cek("{$nama} berubah mengikuti periode", $a !== $b,
            'keluarannya SAMA PERSIS di 2026-05 dan 2019 — grafik ini mengabaikan periodenya');
    }

    echo "\n=== 3. Dua kartu yang dulu tidak tersaring ===\n";

    // Diperiksa khusus: inilah yang jadi keluhannya, dan keduanya harus benar-benar KOSONG
    // di masa lampau — bukan sekadar "berbeda".
    $cek('Rekonsiliasi Piutang berisi di periode datanya',
        count($kini['charts']['piutang']['rows'] ?? []) > 0, 'fikstur piutang tidak terbaca');
    $cek('Rekonsiliasi Piutang KOSONG di masa lampau',
        count($lampau['charts']['piutang']['rows'] ?? []) === 0,
        (string) count($lampau['charts']['piutang']['rows'] ?? []).' baris — faktur 2026 ikut terhitung di 2019');

    $cek('Summary HPP berisi di periode datanya',
        count($kini['charts']['hpp']['rows'] ?? []) > 0, 'fikstur HPP tidak terbaca');
    $cek('Summary HPP KOSONG di masa lampau',
        count($lampau['charts']['hpp']['rows'] ?? []) === 0,
        (string) count($lampau['charts']['hpp']['rows'] ?? []).' baris — HPP masa 2026-05 ikut terhitung di 2019');

    echo "\n=== 4. Kejadian SESUDAH periode tidak ikut terhitung ===\n";

    // Dua hal yang hanya ketahuan bila datanya jatuh SETELAH tanggal akhir — bukan di tahun
    // lain. Periode lampau tidak bisa membuktikannya: di sana semuanya memang kosong.

    // (a) UM terkompensasi bulan Juni tidak boleh mengurangi sisa piutang per 31 Mei.
    $sisaSebelum = (float) ($kini['charts']['piutang']['rows'][0]['b'] ?? 0);
    if (Schema::hasTable('bs_ar_um_compensations')) {
        DB::table('bs_ar_um_compensations')->insert([
            'tenant_id' => $tenantId, 'source_type' => 'utj_dp', 'source_id' => 9911,
            'booking_id' => $booking, 'customer_id' => $cust, 'amount' => 5000000,
            'compensated_at' => '2026-06-20 10:00:00', 'compensated_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $meiUm = $render('2026-05-01', '2026-05-31');
        $junUm = $render('2026-06-01', '2026-06-30');
        $sisaMei = (float) ($meiUm['charts']['piutang']['rows'][0]['b'] ?? 0);
        $sisaJun = (float) ($junUm['charts']['piutang']['rows'][0]['b'] ?? 0);
        $cek('UM yang dikompensasi Juni tidak mengurangi sisa piutang per Mei',
            $sisaMei === $sisaSebelum, 'sebelum='.$sisaSebelum.' sesudah='.$sisaMei);
        $cek('tetapi ikut mengurangi pada periode Juni', $sisaJun < $sisaMei,
            'mei='.$sisaMei.' juni='.$sisaJun);
    } else {
        $lewati('UM terkompensasi', 'tabel bs_ar_um_compensations tidak ada');
    }

    // (b) Realisasi budget bulan Agustus tidak boleh terhitung pada periode s/d Mei —
    //     keduanya di TAHUN YANG SAMA, jadi hanya potongan bulan yang bisa memisahkannya.
    if (isset($adaFikstur['budget'])) {
        $realMeiSebelum = (float) ($kini['charts']['budget']['rows'][0]['real'] ?? 0);
        $j2 = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenantId, 'journal_number' => 'UJI-DB-J2', 'journal_date' => '2026-08-10',
            'period_year' => 2026, 'period_month' => 8, 'source' => 'memorial',
            'description' => 'UJI realisasi Agustus', 'status' => 'posted',
            'total_debit' => 9000000, 'total_credit' => 9000000, 'created_by' => $userId,
            'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('bs_journal_lines')->insert([
            'journal_id' => $j2, 'account_id' => $akunLaw, 'cost_center_id' => $cc,
            'description' => 'UJI realisasi Agustus', 'debit' => 9000000, 'credit' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $meiB = $render('2026-05-01', '2026-05-31');
        $aguB = $render('2026-01-01', '2026-08-31');
        $realMei = (float) ($meiB['charts']['budget']['rows'][0]['real'] ?? 0);
        $realAgu = (float) ($aguB['charts']['budget']['rows'][0]['real'] ?? 0);
        $cek('realisasi Agustus tidak terhitung pada periode s/d Mei',
            $realMei === $realMeiSebelum, 'sebelum='.$realMeiSebelum.' sesudah='.$realMei);
        $cek('tetapi ikut terhitung pada periode s/d Agustus', $realAgu > $realMei,
            'mei='.$realMei.' agu='.$realAgu);
    } else {
        $lewati('Realisasi budget per bulan', 'fikstur budget tidak terbentuk');
    }

    echo "\n=== 5. HPP mengikuti masa, bukan sekadar baris terakhir ===\n";

    // Ditambahkan HPP masa BERIKUTNYA dengan angka berbeda. Periode Mei harus tetap membaca
    // angka Mei — bukan angka Juni yang belum terjadi pada masa itu.
    if (isset($adaFikstur['hpp'])) {
        DB::table('bs_cogs_hpp')->insert([
            'period_year' => 2026, 'period_month' => 6, 'project_id' => $proj, 'unit_id' => $unit,
            'hpp_tanah' => 999000000, 'hpp_bangunan' => 0, 'hpp_permit' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $mei  = $render('2026-05-01', '2026-05-31');
        $juni = $render('2026-06-01', '2026-06-30');
        $tanahMei  = (float) ($mei['charts']['hpp']['rows'][0]['a'] ?? 0);
        $tanahJuni = (float) ($juni['charts']['hpp']['rows'][0]['a'] ?? 0);

        $cek('periode Mei membaca HPP masa Mei', $tanahMei === 100000000.0, (string) $tanahMei);
        $cek('periode Juni membaca HPP masa Juni', $tanahJuni === 999000000.0, (string) $tanahJuni);
        $cek('keduanya memang berbeda', $tanahMei !== $tanahJuni, 'HPP tidak mengikuti masa');
    } else {
        $lewati('HPP per masa', 'fikstur HPP tidak terbentuk');
    }

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal} · LEWAT {$lewat}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = DB::table('bs_journals')->where('journal_number', 'like', 'UJI-DB-%')->count()
        + DB::table('customers')->where('code', 'like', 'UJI-DB-%')->count()
        + DB::table('bs_suppliers')->where('code', 'like', 'UJI-DB-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
