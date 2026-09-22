<?php

declare(strict_types=1);

/**
 * Uji: Collection → Rekom Batal Cash (sidebar mandiri di atas Surat SP).
 *
 * Layar ini melakukan DUA hal dalam satu tekan: mencetak Memo Rekom Batal untuk SPR cash,
 * DAN menulis penanda ke ipro_bo.kpr_cancel_recommendations yang membuka tombol "Ajukan
 * Pembatalan" di Pembatalan Kontrak. Keduanya harus benar bersama-sama — memo yang tercetak
 * sementara penandanya gagal meninggalkan operator dengan dokumen resmi di tangan dan tombol
 * yang tetap mati, tanpa satu pun tanda di layar bahwa ada yang gagal.
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. PENYARING "CASH"-nya enum kontrak, bukan nama skema. Nama skema ("CASH 1X"/"CASH 3X")
 *     adalah teks master yang bisa diganti — di repo ini seluruh nama skema pernah diawali
 *     "OLD " oleh sebuah migrasi — dan fo_kontrak.skema_id boleh kosong, sehingga menyaring
 *     lewat relasi skema diam-diam membuang kontrak yang skemanya belum tertaut.
 *  2. PENANDANYA BISA DITULIS TANPA BOOKING. SPR cash hidup di fo_kontrak; barisan
 *     ipro_bo.bookings hanyalah cerminan yang belum tentu ada. Kalau penulisannya menuntut
 *     booking, memonya tercetak dan tombolnya tetap terkunci.
 *  3. SUMBERNYA 'cash_fo' dan source_id = id fo_kontrak. Tanpa sumber yang benar, gerbang
 *     Pembatalan Kontrak menolak membuka (ia memeriksa `source`, lihat
 *     uji-ajukan-pembatalan-terkunci.php).
 *  4. DIULANG TIDAK MENGGANDAKAN, dan created_at baris lama tidak tertimpa.
 *  5. LEMBAR CETAKNYA MENYARING ULANG. Tanpa itu, siapa pun bisa mencetak memo untuk kontrak
 *     KPR atau kontrak yang sudah batal hanya dengan menyusun ?ids= sendiri di alamat.
 *  6. DUA TAHAP KONFIRMASI dengan kata-kata yang diminta, dan "Tidak" pada tahap mana pun
 *     kembali ke halaman semula.
 *  7. PERKABELANNYA TERPASANG: sidebar tepat DI ATAS Surat SP, halaman terdaftar di
 *     config/akses.php, tombol massal di pojok kanan atas, dan urutannya tulis-dulu-cetak-
 *     kemudian.
 *
 * Tulisan FO dibungkus transaksi lalu di-rollback; baris ipro_bo dibersihkan sendiri.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-rekom-batal-cash.php
 */

use App\Filament\Fo\Pages\PembatalanKontrak;
use App\Filament\Fo\Pages\RekomBatalCash;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

// Wajib: tanpa penanda "sampai ujung", galat di tengah jalan (mis. tabrakan kunci unik yang
// justru sedang diuji) meninggalkan $gagal === 0 dan skrip ini akan mencetak SEMUA UJI LULUS
// padahal separuh pemeriksaannya tidak pernah berjalan.
$tuntas = false;

$bo = DB::connection((string) config('ipro.connection', 'ipro_bo'));
$tenant = (int) config('ipro.tenant_id', 1);
$projectId = (int) config('ipro.project_id', 1);
$tabel = fn () => $bo->table('kpr_cancel_recommendations');

$TAG = 'ZRC'.strtoupper(substr((string) getmypid(), -4));

$ref = new ReflectionClass(PembatalanKontrak::class);
$siap = $ref->getMethod('rekomBatalSiap');
$siap->setAccessible(true);
$cache = $ref->getProperty('rekomBatal');
$cache->setAccessible(true);
$nilaiGerbang = function (Kontrak $k) use ($siap, $cache): bool {
    $cache->setValue(null, null);

    return (bool) $siap->invoke(null, $k);
};

DB::beginTransaction();

try {
    $custId = (int) DB::table('fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'code' => 'CUST-'.$TAG,
        'full_name' => 'Naufal Uji Cash', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (int) DB::table('fo_units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode_unit' => 'UNIT-'.$TAG,
        'cluster' => 'Cluster Uji', 'blok' => 'A', 'no_unit' => '21',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $agentId = (int) DB::table('fo_agents')->insertGetId([
        'tenant_id' => $tenant, 'code' => 'AG-'.$TAG, 'name' => 'Sales Uji '.$TAG,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buat = function (string $suffix, string $caraBayar, string $tahap = 'SPR', string $status = 'AKTIF')
        use ($tenant, $custId, $unitId, $agentId, $TAG) {
        $nomor = 'UJI-'.$TAG.'-'.$suffix;
        DB::table('fo_kontrak')->insert([
            'tenant_id' => $tenant, 'no_kontrak' => $nomor, 'customer_id' => $custId, 'unit_id' => $unitId,
            'agent_id' => $agentId, 'tgl_kontrak' => '2026-09-01',
            'harga_jual' => 500000000, 'total_kontrak' => 500000000,
            'cara_bayar' => $caraBayar, 'status' => $status, 'tahap' => $tahap,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Kontrak::where('no_kontrak', $nomor)->first();
    };

    $cash1 = $buat('CASH1', 'CASH');
    $cash3 = $buat('CASH3', 'CASH_BERTAHAP', 'SPRS');
    $kpr = $buat('KPR', 'KPR');
    $cicil = $buat('CICIL', 'CICILAN');
    $batal = $buat('BATAL', 'CASH', 'SPR', 'BATAL');

    echo "\n=== 1. Penyaring daftar ===\n";

    $daftar = RekomBatalCashService::daftar()->pluck('no_kontrak')->all();
    $punya = fn (Kontrak $k) => in_array((string) $k->no_kontrak, $daftar, true);

    $cek('CASH (1X) masuk daftar', $punya($cash1));
    $cek('CASH BERTAHAP (3X) masuk daftar', $punya($cash3));
    $cek('  SPRS ikut, bukan hanya SPR', $punya($cash3) && $cash3->tahap === 'SPRS');
    $cek('KPR TIDAK masuk daftar', ! $punya($kpr),
        'kontrak KPR bisa dibuatkan memo cash — penandanya menimpa penanda dari modul KPR');
    $cek('CICILAN TIDAK masuk daftar', ! $punya($cicil));
    $cek('kontrak BATAL TIDAK masuk daftar', ! $punya($batal),
        'kontrak yang sudah batal masih bisa direkomendasikan batal lagi');

    // Penyaringnya harus enum kontrak. Kalau ia bersandar pada nama skema, kontrak tanpa
    // skema (skema_id NULL — keadaan kelima kontrak uji ini) akan hilang dari daftar.
    $cek('  tidak bersandar pada relasi skema (skema_id kosong tetap muncul)',
        $punya($cash1) && $cash1->skema_id === null);

    echo "\n=== 2. Label cara bayar ===\n";

    $cek('CASH → "CASH 1X"', RekomBatalCashService::label('CASH') === 'CASH 1X');
    $cek('CASH_BERTAHAP → "CASH 3X"', RekomBatalCashService::label('CASH_BERTAHAP') === 'CASH 3X');
    $cek('nama skema menang bila ada', RekomBatalCashService::label('CASH', 'CASH KERAS 1X') === 'CASH KERAS 1X');
    $cek('cara bayar kosong tidak menjadi "CASH 1X"', RekomBatalCashService::label(null) === '—');
    $cek('adalahCash() menolak KPR', RekomBatalCashService::adalahCash('KPR') === false);
    $cek('adalahCash() menolak CICILAN', RekomBatalCashService::adalahCash('CICILAN') === false);

    echo "\n=== 3. Penulisan penanda ===\n";

    $svc = app(RekomBatalCashService::class);
    $hasil = $svc->catat([$cash1, $cash3], 77, 'Admin Uji');

    $cek('dua penanda tercatat', ($hasil['tersimpan'] ?? 0) === 2);
    $cek('  dilaporkan belum tercermin di Back Office', ($hasil['tanpa_booking'] ?? 0) === 2,
        'operator tidak diberi tahu bahwa SPR-nya belum ada di BO');

    $baris = $tabel()->where('spr_number', $cash1->no_kontrak)->first();
    $cek('baris penanda ada', $baris !== null);
    $cek('  booking_id KOSONG (SPR cash belum tercermin)', $baris !== null && $baris->booking_id === null,
        'penulisan menuntut booking — memo tercetak tapi tombol tetap mati');
    // Nilainya ditulis HARFIAH, bukan lewat konstanta: konstanta ini adalah separuh dari
    // kesepakatan dengan KprCancelRecommendation::SUMBER_CASH di aplikasi Back Office, dan
    // keduanya tidak bisa saling meng-import. Membandingkannya dengan dirinya sendiri berarti
    // mengganti nilainya akan lolos di sini sementara sisi BO tidak pernah tahu.
    $cek('  source = cash_fo', $baris !== null && (string) $baris->source === 'cash_fo');
    $cek('  konstanta SUMBER masih cash_fo (sepakat dengan Back Office)',
        RekomBatalCashService::SUMBER === 'cash_fo');
    $cek('  source_id = id fo_kontrak', $baris !== null && (int) $baris->source_id === (int) $cash1->id);
    $cek('  tenant_id sesuai', $baris !== null && (int) $baris->tenant_id === $tenant);
    $cek('  penandatangan tercatat', $baris !== null && (string) $baris->recommended_by_name === 'Admin Uji'
        && (int) $baris->recommended_by === 77);
    $cek('  alasan menyebut cara bayarnya', $baris !== null && str_contains((string) $baris->reason, 'CASH 1X'));
    $cek('  created_at terisi', $baris !== null && $baris->created_at !== null,
        'baris masuk dengan created_at NULL');

    echo "\n=== 4. Diulang tidak menggandakan ===\n";

    $createdAwal = (string) $baris->created_at;
    // Ditulis mundur supaya penimpaan created_at benar-benar terlihat (kalau ditulis ulang
    // dengan now(), nilainya akan sama persis dan ujinya lolos tanpa menguji apa pun).
    $tabel()->where('spr_number', $cash1->no_kontrak)->update(['created_at' => '2020-01-01 00:00:00']);

    $svc->catat([$cash1], 88, 'Admin Kedua');
    $cek('tetap satu baris', $tabel()->where('spr_number', $cash1->no_kontrak)->count() === 1);
    $ulang = $tabel()->where('spr_number', $cash1->no_kontrak)->first();
    $cek('  created_at TIDAK tertimpa', $ulang !== null && str_starts_with((string) $ulang->created_at, '2020-01-01'),
        'jejak kapan rekom batal pertama terbit hilang tiap kali memonya dicetak ulang');
    $cek('  penandatangan terbaru menyusul', $ulang !== null && (string) $ulang->recommended_by_name === 'Admin Kedua');

    echo "\n=== 5. Tombol Ajukan Pembatalan terbuka ===\n";

    $cek('CASH 1X terbuka', $nilaiGerbang($cash1) === true);
    $cek('CASH 3X terbuka', $nilaiGerbang($cash3) === true);
    $cek('kontrak cash lain tetap terkunci', $nilaiGerbang($buat('CASH9', 'CASH')) === false);

    echo "\n=== 6. Lembar cetak menyaring ulang ===\n";

    $cetak = new ClosingPrintController;
    $req = Request::create('/fo/cetak/rekom-batal-cash', 'GET', ['ids' => $cash1->id.','.$kpr->id.','.$batal->id]);
    $html = $cetak->rekomBatalCash($req)->render();
    $html = preg_replace('/<!--.*?-->/s', '', (string) $html);

    $cek('SPR cash tercetak', str_contains($html, (string) $cash1->no_kontrak));
    $cek('SPR KPR TIDAK ikut tercetak walau id-nya disisipkan di ?ids=',
        ! str_contains($html, (string) $kpr->no_kontrak),
        'memo rekom batal cash bisa dicetak untuk kontrak KPR lewat alamat');
    $cek('kontrak BATAL TIDAK ikut tercetak', ! str_contains($html, (string) $batal->no_kontrak));
    $cek('  jumlah user = 1 (tiga id, satu yang sah)', str_contains($html, '<b>1 (satu)</b>'));
    $cek('  judulnya memo cash, bukan memo KPR',
        str_contains($html, 'Rekomendasi Pembatalan SPR Cash') && ! str_contains($html, 'Pembatalan KPR'));

    foreach (['NO. KONTRAK / SPR', 'TAHAP', 'UNIT', 'NAMA CUSTOMER', 'NAMA SALES', 'NILAI KONTRAK'] as $kolom) {
        $cek('  lampiran memuat kolom '.$kolom, str_contains($html, '<th>'.$kolom.'</th>'));
    }
    $cek('  nilai kontrak terformat rupiah', str_contains($html, '500.000.000'));
    $cek('  nama sales terisi dari kontrak', str_contains($html, 'Sales Uji '.$TAG));
    $cek('  ada jalan pulang ke layar Rekom Batal Cash', str_contains($html, '/fo/rekom-batal-cash'));
    $cek('  kop surat ikut (mup-letterhead ada di FO)', str_contains($html, 'background-image: url(\'data:image'),
        'public/images/mup-letterhead.jpeg belum disalin ke aplikasi FO');

    // GET murni: memuat ulang lembar memo TIDAK boleh menerbitkan rekom batal baru.
    $sebelum = $tabel()->where('spr_number', 'like', 'UJI-'.$TAG.'%')->count();
    $cetak->rekomBatalCash($req)->render();
    $cek('memuat ulang lembar memo tidak menulis penanda baru',
        $tabel()->where('spr_number', 'like', 'UJI-'.$TAG.'%')->count() === $sebelum);

    // Pesannya ikut diperiksa, bukan hanya status: tanpa penjaga "ids kosong" permintaan itu
    // tetap berakhir 404 — dari penjaga BERIKUTNYA, dengan pesan "tidak ada SPR yang cocok"
    // yang menyesatkan operator mencari data, padahal ia cuma belum mencentang apa pun.
    $kosong = '';
    try {
        $cetak->rekomBatalCash(Request::create('/fo/cetak/rekom-batal-cash', 'GET', ['ids' => '']));
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $kosong = $e->getStatusCode().'|'.$e->getMessage();
    }
    $cek('?ids= kosong → 404 "pilih minimal satu"', str_starts_with($kosong, '404|Pilih minimal satu SPR'), $kosong);

    $takCocok = '';
    try {
        $cetak->rekomBatalCash(Request::create('/fo/cetak/rekom-batal-cash', 'GET', ['ids' => (string) $kpr->id]));
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $takCocok = $e->getStatusCode().'|'.$e->getMessage();
    }
    $cek('  ?ids= hanya berisi SPR non-cash → 404 "tidak ada yang cocok"',
        str_starts_with($takCocok, '404|Tidak ada SPR cash'), $takCocok);

    echo "\n=== 7. Dua tahap konfirmasi ===\n";

    $modal = (string) file_get_contents(resource_path('views/filament/fo/partials/konfirmasi-rekom-batal.blade.php'));
    $cek('kalimat tahap 1 apa adanya',
        str_contains($modal, 'apakah anda yakin akan membuat rekom batal?'));
    $cek('kalimat tahap 2 apa adanya',
        str_contains($modal, 'rekom batal ini akan memberikan instruksi ke admin sales untuk pembatalan spr'));
    $cek('  tahap 2 menutup dengan "apakah anda yakin akan hal tersebut?"',
        str_contains($modal, 'apakah anda yakin akan hal tersebut?'));
    $cek('"Ya" tahap 1 berpindah ke tahap 2, bukan langsung menjalankan',
        str_contains($modal, 'x-show="tahap === 1" x-on:click="tahap = 2"'));
    $cek('"Ya" tahap 2 baru menjalankan aksinya',
        str_contains($modal, '$wire.{{ $panggil }}()') && str_contains($modal, 'x-show="tahap === 2"'));
    $cek('"Tidak" menutup modal (kembali ke halaman semula)',
        str_contains($modal, 'Tidak') && str_contains($modal, '$wire.{{ $batal }}()'));

    // Dirakit sungguhan, bukan hanya dibaca sumbernya: tabel Filament yang salah rakit
    // (nama method keliru, aksi tanpa modal) baru meledak saat halamannya dibuka, dan uji
    // yang cuma memindai teks tidak akan pernah melihatnya.
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('fo'));
    $halaman = new RekomBatalCash;
    $tabel = $halaman->table(\Filament\Tables\Table::make($halaman));

    $cek('tabelnya terakit (7 kolom, kotak centang menyala)',
        count($tabel->getColumns()) === 7 && $tabel->isSelectionEnabled() === true);

    $aksiMassal = array_values($tabel->getHeaderActions())[0] ?? null;
    $cek('tombol massal terakit & MATI saat belum ada centang',
        $aksiMassal !== null && $aksiMassal->getName() === 'memoMassal' && $aksiMassal->isDisabled() === true);

    $modalMassal = $aksiMassal?->getModalContent()?->render() ?? '';
    $cek('  modalnya benar-benar terender (blade-nya tidak rusak)',
        substr_count($modalMassal, '<button') === 3, 'tiga tombol: Tidak, Ya(tahap 1), Ya(tahap 2)');
    $cek('  "Ya" tahap 2 memanggil aksi tabel', str_contains($modalMassal, 'callMountedTableAction'));
    $cek('  "Tidak" menutup aksi tabel', str_contains($modalMassal, 'unmountTableAction'));

    $aksiBaris = array_values($tabel->getActions())[0] ?? null;
    $modalBaris = $aksiBaris?->record($cash1)?->getModalContent()?->render() ?? '';
    $cek('modal per baris menyebut nomor SPR-nya',
        str_contains($modalBaris, (string) $cash1->no_kontrak));
    $cek('  memuat kedua kalimat konfirmasi',
        str_contains($modalBaris, 'apakah anda yakin akan membuat rekom batal?')
        && str_contains($modalBaris, 'instruksi ke admin sales'));

    echo "\n=== 8. Perkabelan layar ===\n";

    $srcHal = (string) file_get_contents((new ReflectionClass(RekomBatalCash::class))->getFileName());

    // Dicocokkan TEPAT pada ->query( — bukan "kata itu ada di suatu tempat di berkas":
    // aksi massal juga menyebut daftar(), sehingga pemeriksaan longgar tetap hijau walau
    // tabelnya sendiri sudah diganti query buatan sendiri.
    $cek('tabelnya memakai penyaring bersama, bukan salinan query',
        str_contains($srcHal, '->query(RekomBatalCashService::daftar()'),
        'daftar layar dan gerbang bisa berbeda — kontrak terkunci tapi tidak muncul di sini');
    $cek('  aksi massal pun menyaring lewat daftar()',
        str_contains($srcHal, 'RekomBatalCashService::daftar()->whereKey('),
        'centang yang sudah usang bisa menyeret kontrak KPR / batal ikut ditandai');
    $cek('kedua aksi memakai modal dua tahap yang sama',
        substr_count($srcHal, 'filament.fo.partials.konfirmasi-rekom-batal') === 2);
    $cek('  tombol bawaan modal dimatikan (tidak ada "Ya" ketiga)',
        substr_count($srcHal, '->modalSubmitAction(false)') === 2);
    $cek('tombol massal ada di pojok kanan atas (headerActions)',
        str_contains($srcHal, '->headerActions([') && str_contains($srcHal, "Action::make('memoMassal')"));
    $cek('  labelnya "Memo Rekom Batal (Massal)"', str_contains($srcHal, "'Memo Rekom Batal (Massal)'"));
    // Syaratnya harus ada DI DALAM ->disabled(): tooltipnya memakai kalimat yang sama, jadi
    // pemeriksaan longgar tetap hijau untuk tombol yang sudah selalu aktif.
    $cek('  mati saat belum ada yang dicentang',
        (bool) preg_match('/->disabled\([^\n]*selectedTableRecords/', $srcHal));
    $cek('  kotak centangnya dirender (->selectable())', str_contains($srcHal, '->selectable()'),
        'tanpa ini tombol massal tidak akan pernah punya baris untuk dikerjakan');
    $cek('tombol per baris "Memo Rekom Batal" ada', str_contains($srcHal, "->label('Memo Rekom Batal')"));

    foreach ([
        'No. Kontrak / SPR Final' => "->label('No. Kontrak / SPR Final')",
        'Tahap' => "make('tahap')",
        'Unit' => "make('unit.kode_unit')",
        'Nama Customer' => "->label('Nama Customer')",
        'Nama Sales' => "->label('Nama Sales')",
        'Nilai Kontrak' => "->label('Nilai Kontrak')",
    ] as $label => $potongan) {
        $cek('  kolom '.$label.' ada di tabel', str_contains($srcHal, $potongan));
    }

    // Urutannya menentukan: penanda ditulis LEBIH DULU, dan kegagalannya menghentikan cetak.
    $proses = substr($srcHal, strpos($srcHal, 'private function prosesMemo'));
    $posCatat = strpos($proses, '->catat(');
    $posRedirect = strpos($proses, '->redirect(');
    $cek('penanda ditulis SEBELUM memo dicetak',
        $posCatat !== false && $posRedirect !== false && $posCatat < $posRedirect,
        'memo tercetak lebih dulu — bila penandanya gagal, tombolnya tetap mati tanpa tanda');
    $catch = substr($proses, strpos($proses, 'catch (\Throwable'));
    $cek('  gagal mencatat → BERHENTI, tidak mencetak',
        strpos($catch, 'return;') !== false && strpos($catch, 'return;') < strpos($catch, '}') + 900
            && ! str_contains(substr($catch, 0, (int) strpos($catch, 'return;')), '->redirect('));
    $cek('  operator diberi tahu saat gagal', str_contains($catch, 'GAGAL dicatat'));

    $srcPanel = (string) file_get_contents(app_path('Providers/Filament/FoPanelProvider.php'));
    $posRekom = strpos($srcPanel, "\$item('Rekom Batal Cash'");
    $posSp = strpos($srcPanel, "\$item('Surat SP'");
    $cek('sidebar "Rekom Batal Cash" ada', $posRekom !== false);
    $cek('  letaknya TEPAT DI ATAS "Surat SP"', $posRekom !== false && $posSp !== false && $posRekom < $posSp);
    $cek('  menunjuk rute halaman yang benar',
        str_contains($srcPanel, "'filament.fo.pages.rekom-batal-cash'"));
    // Slug berawalan "collection" membuat item "Surat SP" ikut menyala
    // (isActiveWhen memakai request()->is('fo/collection*')).
    $cek('  slugnya tidak diawali "collection"',
        RekomBatalCash::getSlug() === 'rekom-batal-cash');

    $cek('halaman terdaftar di config/akses.php',
        config('akses.halaman.RekomBatalCash') === 'fo.rekom_batal_cash',
        'halaman tanpa pemetaan izin lolos untuk SELURUH pengguna FO');

    $cek('rute cetaknya terdaftar', app('router')->getRoutes()->getByName('fo.cetak.rekom-batal-cash') !== null);

    echo "\n=== 9. Gagal menulis penanda TIDAK ditelan ===\n";

    // Kalau catat() menelan galatnya dan memulangkan ringkasan biasa, layar akan tetap
    // mencetak memo dan tidak ada yang tahu penandanya tidak pernah masuk.
    $konnAsli = config('ipro.connection');
    config(['ipro.connection' => 'koneksi_yang_tidak_ada']);
    $melempar = false;
    try {
        app(RekomBatalCashService::class)->catat([$cash1], 1, 'X');
    } catch (\Throwable $e) {
        $melempar = true;
    }
    config(['ipro.connection' => $konnAsli]);
    $cek('ipro_bo putus → catat() melempar (bukan diam-diam "berhasil")', $melempar);

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n";
} finally {
    DB::rollBack();
    $bo->table('kpr_cancel_recommendations')->where('spr_number', 'like', 'UJI-'.$TAG.'%')->delete();

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0 && $tuntas) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
    echo "(data uji dibatalkan & baris ipro_bo dibersihkan)\n";
}
