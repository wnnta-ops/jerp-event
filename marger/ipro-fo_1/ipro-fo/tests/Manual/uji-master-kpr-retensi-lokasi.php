<?php

/**
 * Master Data KPR — (1) Collection Retensi memuat SELURUH pencairan Proses KPR termasuk yang
 * belum berkategori, (2) kolom SISA RETENSI = Outstanding Pencairan, dan (3) kolom Lokasi & Tahap
 * dari sub-modul Unit aplikasi Front Office.
 *
 * TIGA HAL YANG DIJAGA, ketiganya tidak memunculkan galat apa pun kalau salah:
 *
 *  1. PENCAIRAN TANPA KATEGORI DULU DIBUANG DIAM-DIAM. retention_category_id baru ada setelah
 *     master kategori diperkenalkan; baris lama berkolom NULL dan tersaring habis oleh
 *     whereNotNull, sehingga jumlah pencairan di layar ini LEBIH KECIL daripada di Proses KPR —
 *     selisih yang hanya ketahuan kalau seseorang menjumlahkan keduanya.
 *  2. SISA RETENSI HARUS MEMAKAI RUMUS YANG SAMA PERSIS dengan Proses KPR. Menyederhanakannya
 *     menjadi "plafon − pencairan" akan berbeda untuk SETIAP KPR yang punya retensi ditahan, dan
 *     dua-duanya terlihat masuk akal.
 *  3. LOKASI & TAHAP DIBACA LINTAS BASIS DATA. Kalau gagal, kolomnya harus kosong — bukan
 *     menjatuhkan seluruh laporan.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-master-kpr-retensi-lokasi.php';"
 */

use App\Http\Controllers\Web\BankLoan\MasterDataKprController;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->can('bankloan.application.view')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

DB::beginTransaction();

try {
    $tenantId  = (int) DB::table('tenants')->orderBy('id')->value('id');
    $projectId = (int) DB::table('projects')->orderBy('id')->value('id');
    $unitId    = (int) DB::table('units')->orderBy('id')->value('id');
    $skemaKpr  = (int) DB::table('payment_schemes')->where('type', 'kpr')->value('id');
    $bankId    = (int) DB::table('bank_partners')->orderBy('id')->value('id');
    $kategori  = DB::table('retention_categories')->whereNull('deleted_at')->where('is_active', 1)
        ->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'is_dajam']);
    $katPertama = $kategori->first();
    $now = now();

    $ok('prasyarat master tersedia', $skemaKpr > 0 && $unitId > 0 && $katPertama !== null);
    if (! $skemaKpr || ! $unitId || ! $katPertama) { DB::rollBack(); echo "Prasyarat kurang.\n"; return; }

    $custId = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-RET-C', 'full_name' => 'UJI Retensi',
        'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $bookingId = (int) DB::table('bookings')->insertGetId([
        'tenant_id' => $tenantId, 'project_id' => $projectId, 'unit_id' => $unitId, 'customer_id' => $custId,
        'booking_number' => 'UJI-SPR-RET-'.random_int(100, 999), 'booking_date' => $now->toDateString(),
        'payment_scheme_id' => $skemaKpr, 'scheme_name' => 'KPR', 'base_price' => 500000000,
        'total_price' => 500000000, 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $appId = (int) DB::table('kpr_applications')->insertGetId([
        'tenant_id' => $tenantId, 'application_number' => 'UJI-KPR-RET-'.random_int(100, 999),
        'booking_id' => $bookingId, 'customer_id' => $custId, 'bank_partner_id' => $bankId ?: null,
        // Diajukan SENGAJA BERBEDA dari yang disetujui: kalau keduanya sama, memakai kolom yang
        // keliru tidak mengubah angka apa pun dan ujinya hijau tanpa menguji apa pun.
        'plafon_requested' => 470000000, 'plafon_approved' => 500000000, 'status' => 'disbursed',
        'submitted_at' => $now, 'tenor_month' => 180,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    /* Tiga pencairan:
         A. berkategori, 100 jt
         B. TANPA kategori (data lama), 60 jt   ← yang dulu hilang dari layar
         C. berkategori, 40 jt, dengan retensi 15 jt yang MASIH DITAHAN */
    $realA = (int) DB::table('kpr_realizations')->insertGetId([
        'kpr_application_id' => $appId, 'disbursed_at' => '2026-03-01', 'disbursed_amount' => 100000000,
        'retention_category_id' => $katPertama->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $realB = (int) DB::table('kpr_realizations')->insertGetId([
        'kpr_application_id' => $appId, 'disbursed_at' => '2026-04-02', 'disbursed_amount' => 60000000,
        'retention_category_id' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $realC = (int) DB::table('kpr_realizations')->insertGetId([
        'kpr_application_id' => $appId, 'disbursed_at' => '2026-05-03', 'disbursed_amount' => 40000000,
        'retention_category_id' => $katPertama->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('kpr_retentions')->insert([
        'kpr_realization_id' => $realC, 'amount' => 15000000, 'released_amount' => 0,
        'status' => 'held', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $ctl = new MasterDataKprController;
    $req = \Illuminate\Http\Request::create('/kpr/master-data', 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $props = json_decode((string) $ctl->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
    $cols = $props['retensiCols'] ?? [];
    $row  = collect($props['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number'));

    echo "\n=== 1. Kolom TANPA KATEGORI ada, paling kanan sesudah kategori master ===\n";

    $ok('barisnya ada di layar', $row !== null);
    $ok('kolom TANPA KATEGORI ada', in_array('TANPA KATEGORI', $cols, true), json_encode($cols));
    $ok('ia kolom TERAKHIR (sesudah DAJAM)', end($cols) === 'TANPA KATEGORI', (string) end($cols));
    $ok('jumlah sel retensi = jumlah judulnya',
        is_array($row['retensi'] ?? null) && count($row['retensi']) === count($cols),
        'sel='.count($row['retensi'] ?? []).' judul='.count($cols));

    echo "\n=== 2. Pencairan tanpa kategori TIDAK lagi hilang ===\n";

    $iTanpa = array_search('TANPA KATEGORI', $cols, true);
    $selTanpa = $row['retensi'][$iTanpa] ?? null;
    $ok('selnya terisi', $selTanpa !== null, 'pencairan lama masih dibuang diam-diam');
    $ok('nominalnya 60 jt', (float) ($selTanpa['n'] ?? 0) === 60000000.0, json_encode($selTanpa));
    $ok('tanggalnya 2 Apr 2026', ($selTanpa['t'] ?? null) === '2026-04-02', json_encode($selTanpa));

    $iKat = array_search($katPertama->code, $cols, true);
    $selKat = $row['retensi'][$iKat] ?? null;
    $ok('kolom berkategori tetap menjumlah kedua pencairannya (100 + 40)',
        (float) ($selKat['n'] ?? 0) === 140000000.0, json_encode($selKat));
    // Inilah tujuan seluruh perubahan ini.
    $totalLayar = array_sum(array_map(fn ($c) => (float) ($c['n'] ?? 0), array_filter((array) $row['retensi'])));
    $totalProses = (float) DB::table('kpr_realizations')->where('kpr_application_id', $appId)->sum('disbursed_amount');
    $ok('JUMLAH seluruh kolom retensi = jumlah pencairan di Proses KPR',
        abs($totalLayar - $totalProses) < 0.01, "layar=$totalLayar proses=$totalProses");

    echo "\n=== 3. SISA RETENSI = Outstanding Pencairan ===\n";

    /* kotor 200 jt − ditahan 15 jt = cair 185 jt; plafon 500 jt → sisa 315 jt.
       Rumus sederhana "plafon − kotor" akan memberi 300 jt — beda 15 jt, dan sama-sama masuk akal. */
    $ok('sisa_retensi dikirim ke layar', array_key_exists('sisa_retensi', (array) $row), 'kolomnya tidak akan pernah terisi');
    $ok('nilainya 315 jt (retensi ditahan MENAMBAH outstanding)',
        (float) ($row['sisa_retensi'] ?? 0) === 315000000.0,
        'dapat '.json_encode($row['sisa_retensi'] ?? null).' — rumusnya tidak sama dengan Proses KPR');
    $ok('BUKAN 300 jt (plafon − pencairan kotor)', (float) ($row['sisa_retensi'] ?? 0) !== 300000000.0);

    // Retensi DITOLAK → dianggap ditahan penuh lagi, persis seperti di layar Proses KPR.
    DB::table('kpr_retentions')->where('kpr_realization_id', $realC)
        ->update(['released_amount' => 15000000, 'approval_status' => 'rejected']);
    $ctl2 = new MasterDataKprController;
    $props2 = json_decode((string) $ctl2->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
    $row2 = collect($props2['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number'));
    $ok('retensi DITOLAK tetap dihitung ditahan → sisa tetap 315 jt',
        (float) ($row2['sisa_retensi'] ?? 0) === 315000000.0, json_encode($row2['sisa_retensi'] ?? null));

    // Retensi DILEPAS & disetujui → menambah pencairan, sisa turun.
    DB::table('kpr_retentions')->where('kpr_realization_id', $realC)
        ->update(['released_amount' => 15000000, 'approval_status' => 'approved']);
    $ctl3 = new MasterDataKprController;
    $props3 = json_decode((string) $ctl3->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
    $row3 = collect($props3['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number'));
    $ok('retensi dilepas & disetujui → sisa turun jadi 300 jt',
        (float) ($row3['sisa_retensi'] ?? 0) === 300000000.0, json_encode($row3['sisa_retensi'] ?? null));

    echo "\n=== 4. Lokasi & Tahap dari sub-modul Unit Front Office ===\n";

    /* FIKSTUR DI BASIS DATA FO. Tanpa baris fo_units yang kode unitnya cocok, seluruh jalur
       lintas basis data tidak pernah benar-benar dijalani: kolomnya null karena TIDAK KETEMU,
       bukan karena pembacaannya benar — dan lokasi yang tertukar dengan tahap pun tidak
       ketahuan. Kedua basis data berada pada SATU koneksi, jadi sisipan ini ikut ter-rollback. */
    $dbFo = (string) config('bankloan.fo_database', '');
    $kodeUnit = (string) DB::table('units')->where('id', $unitId)->value('unit_code');
    $fiksturFo = false;
    $galatFo = '';
    if ($dbFo !== '' && preg_match('/^[A-Za-z0-9_]+$/', $dbFo) === 1) {
        try {
            // Tenant & project diambil dari BARIS FO YANG SUDAH ADA: id milik Back Office belum
            // tentu ada di Front Office, dan kunci asingnya akan menolak.
            $acuan = DB::selectOne('SELECT tenant_id, project_id FROM `'.$dbFo.'`.fo_lokasi LIMIT 1');
            $foTenant = (int) ($acuan->tenant_id ?? $tenantId);
            $foProject = (int) ($acuan->project_id ?? $projectId);
            DB::statement('INSERT INTO `'.$dbFo.'`.fo_lokasi (tenant_id, project_id, kode, nama, is_active, created_at, updated_at)'
                .' VALUES (?, ?, ?, ?, 1, ?, ?)', [$foTenant, $foProject, 'UJILOK', 'UJI Lokasi', $now, $now]);
            $lokId = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS id')->id;
            DB::statement('INSERT INTO `'.$dbFo.'`.fo_units (tenant_id, project_id, lokasi_id, tahap, no_unit, kode_unit, status, is_active, created_at, updated_at)'
                .' VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', [$foTenant, $foProject, $lokId, '077', 'UJI-NO', $kodeUnit, 'TERSEDIA', $now, $now]);
            $fiksturFo = true;
        } catch (\Throwable $e) {
            $fiksturFo = false;   // skema FO berbeda / tak terjangkau
            $galatFo = $e->getMessage();
        }
    }
    $ok('fikstur unit di basis data FO terbuat', $fiksturFo,
        'jalur lintas basis data tidak bisa diuji: '.$galatFo);

    /* BOOKING PEMBANDING pada unit yang SENGAJA TIDAK ADA di fo_units. Tanpa baris ini,
       pemetaan yang keliru — setiap unit diisi nilai unit yang kebetulan ada — tetap
       menghasilkan angka benar pada baris fikstur dan lolos tanpa suara. */
    $kodeUnit2 = '';
    $bookingId2 = 0;
    if ($fiksturFo) {
        foreach (DB::table('units')->where('id', '!=', $unitId)->orderBy('id')->get(['id', 'unit_code']) as $u) {
            $adaDiFo = DB::selectOne('SELECT 1 AS a FROM `'.$dbFo.'`.fo_units WHERE kode_unit = ? AND deleted_at IS NULL LIMIT 1',
                [(string) $u->unit_code]);
            if (! $adaDiFo) {
                $kodeUnit2 = (string) $u->unit_code;
                $bookingId2 = (int) DB::table('bookings')->insertGetId([
                    'tenant_id' => $tenantId, 'project_id' => $projectId, 'unit_id' => (int) $u->id,
                    'customer_id' => $custId, 'booking_number' => 'UJI-SPR-NOFO-'.random_int(100, 999),
                    'booking_date' => $now->toDateString(), 'payment_scheme_id' => $skemaKpr,
                    'scheme_name' => 'KPR', 'base_price' => 300000000, 'total_price' => 300000000,
                    'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                break;
            }
        }
    }

    if ($fiksturFo) {
        $ctlFo = new MasterDataKprController;
        $propsFo = json_decode((string) $ctlFo->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
        $rowFo = collect($propsFo['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number'));

        $ok('lokasi terbaca dari fo_lokasi.kode', ($rowFo['lokasi'] ?? null) === 'UJILOK',
            'dapat '.json_encode($rowFo['lokasi'] ?? null));
        $ok('tahap terbaca dari fo_units.tahap', ($rowFo['tahap'] ?? null) === '077',
            'dapat '.json_encode($rowFo['tahap'] ?? null));
        // Keduanya sengaja berbeda bentuk (huruf vs angka) supaya tertukar langsung ketahuan.
        $ok('lokasi & tahap TIDAK tertukar',
            ($rowFo['lokasi'] ?? null) !== '077' && ($rowFo['tahap'] ?? null) !== 'UJILOK',
            json_encode([$rowFo['lokasi'] ?? null, $rowFo['tahap'] ?? null]));

        $ok('ada booking pembanding pada unit di luar FO', $bookingId2 > 0,
            'semua unit Back Office kebetulan ada di Front Office — pembandingnya tidak terbentuk');
        $rowLuar = $bookingId2 > 0
            ? collect($propsFo['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId2)->value('booking_number'))
            : null;
        $ok('unit di luar FO: lokasi & tahap KOSONG, bukan warisan unit lain',
            $bookingId2 > 0 && ($rowLuar['lokasi'] ?? null) === null && ($rowLuar['tahap'] ?? null) === null,
            'unit '.$kodeUnit2.' tidak ada di fo_units tetapi terisi '.json_encode([$rowLuar['lokasi'] ?? null, $rowLuar['tahap'] ?? null]));
        $ok('baris fikstur TETAP terisi di saat yang sama', ($rowFo['lokasi'] ?? null) === 'UJILOK',
            'pemetaan per kode unit tidak berjalan');

        // Basis data bernama sah tetapi TIDAK ADA → kueri melempar; laporan harus tetap hidup.
        config(['bankloan.fo_database' => 'basisdatafoyangtidakada']);
        $ctlHilang = new MasterDataKprController;
        $hidup = true;
        try {
            $propsHilang = json_decode((string) $ctlHilang->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
        } catch (\Throwable $e) {
            $hidup = false;
        }
        $ok('basis data FO tidak ada → laporan TETAP hidup', $hidup,
            'satu galat lintas basis data menjatuhkan seluruh Master Data KPR');
        $rowHilang = $hidup ? collect($propsHilang['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number')) : null;
        $ok('kolom lokasi/tahap kosong saat FO tak terjangkau',
            $hidup && ($rowHilang['lokasi'] ?? null) === null && ($rowHilang['tahap'] ?? null) === null,
            json_encode([$rowHilang['lokasi'] ?? null, $rowHilang['tahap'] ?? null]));
        $ok('kolom lain tetap terisi saat FO tak terjangkau',
            $hidup && ($rowHilang['no_spr'] ?? null) !== null && ($rowHilang['sisa_retensi'] ?? null) !== null);
        config(['bankloan.fo_database' => $dbFo]);
    }

    $ok('kunci lokasi dikirim ke layar', array_key_exists('lokasi', (array) $row));
    $ok('kunci tahap dikirim ke layar', array_key_exists('tahap', (array) $row));

    $ok('nama basis data FO dikonfigurasi, bukan ditulis mati', $dbFo !== '', 'config bankloan.fo_database kosong');

    // Dicocokkan dengan isi fo_units yang SEBENARNYA: bila unit uji ini memang ada di sana,
    // nilainya harus sama; bila tidak ada, kolomnya harus null — bukan tebakan dari kode unit.
    $fo = null;
    if ($dbFo !== '' && preg_match('/^[A-Za-z0-9_]+$/', $dbFo) === 1) {
        try {
            $fo = DB::selectOne(
                'SELECT u.kode_unit, l.kode AS lokasi, u.tahap FROM `'.$dbFo.'`.fo_units u'
                .' LEFT JOIN `'.$dbFo.'`.fo_lokasi l ON l.id = u.lokasi_id'
                .' WHERE u.deleted_at IS NULL AND u.kode_unit = ? LIMIT 1',
                [$kodeUnit],
            );
        } catch (\Throwable $e) {
            $fo = false;   // FO tak terjangkau
        }
    }

    // Baris yang DIBACA ULANG sesudah fikstur FO dipasang: $row diambil sebelum fikstur ada,
    // jadi memakainya di sini akan membandingkan layar lama dengan isi FO yang baru.
    $rowKini = $fiksturFo ? $rowFo : $row;
    if ($fo === false) {
        $ok('FO tak terjangkau → kolomnya kosong, laporan tetap hidup',
            ($rowKini['lokasi'] ?? null) === null && ($rowKini['tahap'] ?? null) === null,
            json_encode([$rowKini['lokasi'] ?? null, $rowKini['tahap'] ?? null]));
    } elseif ($fo === null) {
        $ok('unit tidak ada di FO → kolomnya null, bukan tebakan dari kode unit',
            ($rowKini['lokasi'] ?? null) === null && ($rowKini['tahap'] ?? null) === null,
            'unit '.$kodeUnit.' tidak ada di fo_units, tetapi kolomnya terisi: '.json_encode([$rowKini['lokasi'] ?? null, $rowKini['tahap'] ?? null]));
    } else {
        $ok('lokasi sama dengan fo_lokasi.kode', ($rowKini['lokasi'] ?? null) === ($fo->lokasi ?: null),
            'layar='.json_encode($rowKini['lokasi'] ?? null).' FO='.json_encode($fo->lokasi));
        $ok('tahap sama dengan fo_units.tahap', ($rowKini['tahap'] ?? null) === ($fo->tahap ?: null),
            'layar='.json_encode($rowKini['tahap'] ?? null).' FO='.json_encode($fo->tahap));
    }

    /* PENYARINGAN NAMA BASIS DATA. Pengenal tidak bisa di-bind sebagai parameter, jadi ia
       disisipkan langsung ke SQL — satu-satunya pelindungnya adalah saringan pola. Tanpa
       saringan itu hasil di layar SAMA PERSIS (kueri melempar, try/catch mengembalikan kosong),
       jadi yang diukur di sini bukan tampilannya melainkan APAKAH KUERINYA SEMPAT DIJALANKAN:
       nama yang tidak sah harus ditolak sebelum menyentuh basis data. */
    $asliHandler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
    $mataMata = new class($asliHandler) implements \Illuminate\Contracts\Debug\ExceptionHandler
    {
        public int $jumlah = 0;

        public function __construct(private $asli) {}

        public function report(\Throwable $e)
        {
            $this->jumlah++;
        }

        public function shouldReport(\Throwable $e)
        {
            return true;
        }

        public function render($request, \Throwable $e)
        {
            return $this->asli->render($request, $e);
        }

        public function renderForConsole($output, \Throwable $e)
        {
            $this->asli->renderForConsole($output, $e);
        }
    };
    app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $mataMata);

    // Pembanding: nama BERPOLA SAH tetapi tidak ada → kueri memang dijalankan dan gagal.
    config(['bankloan.fo_database' => 'basisdatafoyangtidakada']);
    $mataMata->jumlah = 0;
    (new MasterDataKprController)->index($req)->toResponse($req)->getContent();
    $galatSah = $mataMata->jumlah;
    $ok('nama berpola sah memang dicoba ke basis data', $galatSah > 0,
        'penghitung galat tidak bekerja — uji saringan di bawah jadi tidak bermakna');

    config(['bankloan.fo_database' => 'basis;data--jahat']);
    $mataMata->jumlah = 0;
    (new MasterDataKprController)->index($req)->toResponse($req)->getContent();
    $ok('nama tidak sah DITOLAK sebelum menyentuh basis data', $mataMata->jumlah === 0,
        'pengenal mentah tetap disisipkan ke SQL — galat terlapor '.$mataMata->jumlah.' kali');
    app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $asliHandler);

    // Nama basis data asing tidak boleh menjatuhkan laporan.
    config(['bankloan.fo_database' => 'basis;data--jahat']);
    $ctl4 = new MasterDataKprController;
    $aman = true;
    try {
        $props4 = json_decode((string) $ctl4->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
    } catch (\Throwable $e) {
        $aman = false;
    }
    $ok('nama basis data tidak sah ditolak & laporan tetap hidup', $aman, 'laporannya ikut mati');
    $row4 = $aman ? collect($props4['rows'] ?? [])->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number')) : null;
    $ok('kolomnya kosong saat nama basis datanya ditolak',
        $aman && ($row4['lokasi'] ?? null) === null, json_encode($row4['lokasi'] ?? null));
    $ok('kolom lain TIDAK ikut kosong', $aman && ($row4['no_spr'] ?? null) !== null);

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} finally {
    DB::rollBack();
}
