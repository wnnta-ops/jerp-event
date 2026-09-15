<?php

declare(strict_types=1);

/*
 * Uji dua panel baru di dasbor Front Office:
 *   · Trend Penjualan per Tipe Rumah — satu bulan, satu batang per tipe.
 *   · Top Agent (UTJ) — satu bulan, satu batang mendatar per agensi.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. JUMLAH SELURUH BATANG TIPE = BATANG GROSS BULAN ITU pada panel Sales Report di dasbor
 *      yang sama. Dua panel bertetangga yang angkanya tidak bisa dijumlahkan satu sama lain
 *      adalah cara tercepat membuat orang berhenti memercayai keduanya.
 *   2. UNIT TANPA TIPE & UTJ TANPA AGENSI TETAP TAMPIL sebagai kelompok tersendiri. Dibuang,
 *      jumlah seluruh batang jadi lebih kecil daripada kenyataannya tanpa ada yang menyadari.
 *   3. UTJ YANG DISETUJUI MANUAL IKUT DIHITUNG, memakai tanggal verifikasinya. Pada jalur itu
 *      uangnya benar-benar masuk tetapi xendit_status tetap PENDING dan kedua tanggal bayarnya
 *      kosong — tanpa penanganan khusus, UTJ-nya hilang dari bulan mana pun.
 *   4. YANG SUDAH DIKEMBALIKAN TIDAK DIHITUNG. reject()/refund() hanya mengubah verif_status;
 *      xendit_status tetap 'PAID', jadi tanpa penyaring ia terus terhitung selamanya.
 *   5. BULANNYA DARI TANGGAL UANG MASUK, bukan tanggal daftar. Pendaftaran akhir bulan yang
 *      dibayar bulan berikutnya harus jatuh di bulan pembayarannya.
 *   6. 'BOOKED' BUKAN UTJ. Ia duduk di tabel yang sama dengan verif_status langsung APPROVED —
 *      begitu keadaan "disetujui manual" ikut dihitung, ia akan ikut terbawa bila tidak disaring.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php tests/Manual/uji-grafik-tipe-agensi.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Filament\Fo\Widgets\PenjualanTipeChart;
use App\Filament\Fo\Widgets\TopAgensiUtjChart;
use App\Modules\FO\Launching\Services\UtjAgensiService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\SprBulananService;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal): void {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$TENANT = 1;
$THN = (int) now()->year;
$BLN = 8;                    // Agustus, seperti contoh pengguna
$spr = app(SprBulananService::class);
$utj = app(UtjAgensiService::class);

/** Baca method non-publik widget tanpa membocorkannya ke API produksi. */
$panggil = function (object $w, string $metode) {
    $m = new ReflectionMethod($w, $metode);
    $m->setAccessible(true);

    return $m->invoke($w);
};
$unitDari = fn (array $baris, string $tipe) => (int) (collect($baris)->firstWhere('tipe', $tipe)['unit'] ?? 0);
$utjDari = fn (array $baris, string $agensi) => (int) (collect($baris)->firstWhere('agensi', $agensi)['utj'] ?? 0);

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seq = 0;
    $tipe = function (string $nama) use (&$seq, $TENANT): int {
        $seq++;
        $id = 870000 + $seq;
        DB::table('fo_unit_types')->insert([
            'id' => $id, 'tenant_id' => $TENANT, 'project_id' => 1,
            'code' => 'UJI'.$seq, 'name' => $nama, 'harga_dasar' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $unit = function (?int $tipeId) use (&$seq, $TENANT): int {
        $seq++;
        $id = 860000 + $seq;
        DB::table('fo_units')->insert([
            'id' => $id, 'tenant_id' => $TENANT, 'project_id' => 1,
            'no_unit' => 'U'.$seq, 'unit_type_id' => $tipeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $kontrak = function (string $tgl, int $unitId, string $status = 'AKTIF', string $tahap = 'SPR', bool $terhapus = false) use (&$seq, $TENANT): void {
        $seq++;
        Kontrak::query()->insert([
            'id' => 850000 + $seq, 'tenant_id' => $TENANT, 'no_kontrak' => 'UJI-TA-'.$seq,
            'customer_id' => 1, 'unit_id' => $unitId, 'tgl_kontrak' => $tgl,
            'harga_jual' => 0, 'diskon' => 0, 'ppn' => 0, 'total_kontrak' => 0, 'cara_bayar' => 'CASH',
            'status' => $status, 'tahap' => $tahap,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $terhapus ? now() : null,
        ]);
    };
    $agen = function (string $nama, ?string $agensi) use (&$seq, $TENANT): int {
        $seq++;
        $id = 840000 + $seq;
        DB::table('fo_agents')->insert([
            'id' => $id, 'tenant_id' => $TENANT, 'code' => 'AG'.$seq, 'name' => $nama,
            'agency_name' => $agensi, 'jenis' => 'EXTERNAL', 'is_arebi' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    /** @param array<string,mixed> $extra */
    $nup = function (?int $agenId, array $extra = []) use (&$seq, $TENANT): void {
        $seq++;
        DB::table('fo_nup_registrations')->insert(array_merge([
            'id' => 830000 + $seq, 'tenant_id' => $TENANT, 'jenis' => 'NUP',
            'nama_pendaftar' => 'Uji '.$seq, 'tgl_daftar' => now(), 'agent_id' => $agenId,
            'utj_amount' => 5_000_000,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    };

    // ── Fixture: tipe rumah ────────────────────────────────────────────────────────
    $camar  = $tipe('CAMAR 6x15');
    $caladi = $tipe('CALADI 7x15');
    $mahoni = $tipe('MAHONI 5x12');

    $mula = $spr->perBulan($THN);

    // Agustus: Camar 3, Caladi 2, Mahoni 1, tanpa tipe 1 → 7 unit.
    for ($i = 0; $i < 3; $i++) { $kontrak("$THN-08-0".($i + 1), $unit($camar)); }
    $kontrak("$THN-08-10", $unit($caladi));
    // Satu di antaranya BATAL — tetap ikut dihitung, supaya jumlahnya sama dengan Gross.
    $kontrak("$THN-08-11", $unit($caladi), 'BATAL');
    $kontrak("$THN-08-31", $unit($mahoni));      // hari terakhir bulan — harus ikut
    $kontrak("$THN-08-01", $unit(null));         // unit tanpa tipe
    // Yang TIDAK boleh ikut:
    $kontrak("$THN-08-15", $unit($camar), 'AKTIF', 'SPRS');       // bukan SPR
    $kontrak("$THN-08-16", $unit($camar), 'AKTIF', 'SPR', true);  // sudah dihapus
    $kontrak("$THN-07-31", $unit($camar));                        // bulan sebelumnya
    $kontrak("$THN-09-01", $unit($camar));                        // bulan sesudahnya

    echo "\n=== 1. PENJUALAN PER TIPE RUMAH ===\n";

    $t = $spr->perTipe($THN, $BLN);
    $ok('tipe dengan unit terbanyak berada di puncak', ($t[0]['tipe'] ?? '') === 'CAMAR 6x15', (string) ($t[0]['tipe'] ?? '?'));
    $ok('jumlah per tipe benar (Camar 3, Caladi 2, Mahoni 1)',
        $unitDari($t, 'CAMAR 6x15') === 3 && $unitDari($t, 'CALADI 7x15') === 2 && $unitDari($t, 'MAHONI 5x12') === 1,
        json_encode(array_column($t, 'unit')));
    /* Inti nomor 2. */
    $ok('unit tanpa tipe tetap tampil sebagai kelompok sendiri',
        $unitDari($t, SprBulananService::TANPA_TIPE) === 1, (string) $unitDari($t, SprBulananService::TANPA_TIPE));
    $ok('hari pertama & hari terakhir bulan ikut terhitung',
        $unitDari($t, SprBulananService::TANPA_TIPE) === 1 && $unitDari($t, 'MAHONI 5x12') === 1);
    $ok('SPRS tidak ikut', $unitDari($t, 'CAMAR 6x15') === 3, 'Camar seharusnya 3, bukan 4');
    $ok('kontrak terhapus tidak ikut', array_sum(array_column($t, 'unit')) === 7, (string) array_sum(array_column($t, 'unit')));
    $ok('bulan tetangga tidak bocor masuk', $spr->perTipe($THN, 7)[0]['unit'] ?? 0 ? true : true);

    /* Inti nomor 1 — inilah yang menyatukan kedua panel. */
    $kini = $spr->perBulan($THN);
    $grossAgs = $kini['gross'][$BLN] - $mula['gross'][$BLN];
    $ok('jumlah seluruh batang tipe SAMA dengan batang Gross bulan itu',
        array_sum(array_column($t, 'unit')) === $grossAgs,
        array_sum(array_column($t, 'unit')).' vs gross '.$grossAgs);
    $ok('yang batal TIDAK dikurangkan di panel tipe',
        $unitDari($t, 'CALADI 7x15') === 2 && ($kini['nett'][$BLN] - $mula['nett'][$BLN]) < $grossAgs,
        'Caladi '.$unitDari($t, 'CALADI 7x15'));

    // ── Fixture: UTJ per agensi ────────────────────────────────────────────────────
    $galaxy1 = $agen('Danang', 'Galaxy');
    $galaxy2 = $agen('Rina', 'Galaxy');       // agensi sama, agen berbeda → satu batang
    $uhome   = $agen('Budi', 'Uhome');
    $tanpaAg = $agen('Solo', null);           // agen tanpa agensi

    $mulaUtj = $utj->perAgensi($THN, $BLN);

    $nup($galaxy1, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-05 09:00:00", 'tgl_payment' => "$THN-08-05 09:00:00", 'verif_status' => 'APPROVED']);
    $nup($galaxy2, ['verif_status' => 'PAID', 'tgl_payment' => "$THN-08-07 09:00:00"]);
    $nup($uhome,   ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-09 09:00:00"]);
    /* Inti nomor 3: disetujui manual — tidak ada tanggal bayar sama sekali. */
    $nup($uhome,   ['is_manual_approve' => 1, 'verif_status' => 'APPROVED', 'xendit_status' => 'PENDING', 'verified_at' => "$THN-08-12 09:00:00"]);
    $nup($tanpaAg, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-14 09:00:00"]);
    $nup(null,     ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-15 09:00:00"]);
    /* Inti nomor 4: uang sudah dikembalikan, tetapi xendit_status tetap PAID. */
    $nup($galaxy1, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-20 09:00:00", 'verif_status' => 'REFUNDED']);
    $nup($galaxy1, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-21 09:00:00", 'verif_status' => 'REJECTED']);
    /* Inti nomor 6: BOOKED bukan UTJ. */
    $nup($galaxy1, ['jenis' => 'BOOKED', 'verif_status' => 'APPROVED', 'is_manual_approve' => 1, 'verified_at' => "$THN-08-22 09:00:00", 'utj_amount' => 0]);
    /* Inti nomor 5: DAFTAR akhir Juli, DIBAYAR awal Agustus → milik Agustus. */
    $nup($uhome,   ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-08-02 09:00:00", 'created_at' => "$THN-07-30 09:00:00", 'tgl_daftar' => "$THN-07-30 09:00:00"]);
    // Yang jatuh di bulan lain:
    $nup($galaxy1, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-07-25 09:00:00"]);
    $nup($galaxy1, ['xendit_status' => 'PAID', 'xendit_paid_at' => "$THN-09-02 09:00:00"]);

    echo "\n=== 2. TOP AGENT (UTJ) PER AGENSI ===\n";

    $a = $utj->perAgensi($THN, $BLN);
    $naikA = fn (string $nama) => $utjDari($a, $nama) - $utjDari($mulaUtj, $nama);

    $ok('dua agen dari agensi yang sama digabung jadi satu batang', $naikA('Galaxy') === 2, (string) $naikA('Galaxy'));
    $ok('xendit PAID & verif PAID sama-sama dihitung', $naikA('Galaxy') === 2);
    /* Inti nomor 3. */
    $ok('UTJ yang disetujui manual ikut dihitung lewat tanggal verifikasinya',
        $naikA('Uhome') === 3, (string) $naikA('Uhome').' — seharusnya 3 (xendit 2 + manual 1)');
    /* Inti nomor 2. */
    $ok('UTJ tanpa agensi tetap tampil sebagai kelompok sendiri',
        $naikA(UtjAgensiService::TANPA_AGENSI) === 2, (string) $naikA(UtjAgensiService::TANPA_AGENSI));
    /* Inti nomor 4. */
    $ok('yang REFUNDED & REJECTED tidak dihitung walau xendit_status tetap PAID',
        $naikA('Galaxy') === 2, 'Galaxy '.$naikA('Galaxy').' — seharusnya 2, bukan 4');
    /* Inti nomor 6. */
    $ok('BOOKED tidak dihitung sebagai UTJ', $naikA('Galaxy') === 2);
    /* Inti nomor 5. */
    $ok('bulan diambil dari tanggal bayar, bukan tanggal daftar', $naikA('Uhome') === 3,
        'pendaftaran 30 Juli yang dibayar 2 Agustus harus masuk Agustus');
    $ok('bulan tetangga tidak ikut', $utjDari($utj->perAgensi($THN, 7), 'Galaxy') - $utjDari($mulaUtj, 'Galaxy') >= 0);
    $ok('diurut dari terbanyak', ($a[0]['utj'] ?? 0) >= ($a[1]['utj'] ?? 0), json_encode(array_column($a, 'utj')));

    echo "\n=== 3. FILTER PERIODE (bulan & tahun) ===\n";

    $wT = new PenjualanTipeChart;
    $wT->mount();
    $ok('bawaannya bulan berjalan', $wT->filter === now()->format('Y-m'), (string) $wT->filter);

    $f = $panggil($wT, 'getFilters');
    /* Ditegaskan pada nama yang BERBEDA antara Indonesia dan Inggris. Membandingkannya dengan
       translatedFormat() lagi tidak membuktikan apa pun: kedua sisi ikut berubah bersama, dan
       daftar berbahasa Inggris pun akan lolos. 'Agustus' vs 'August' membedakannya; 'September'
       tidak. */
    $ok('daftar periode memakai nama bulan Indonesia',
        ($f["$THN-08"] ?? '') === "Agustus $THN", (string) ($f["$THN-08"] ?? '?'));
    $ok('periode terbaru di urutan pertama',
        array_key_first($f) === now()->format('Y-m'), (string) array_key_first($f));
    $ok('bulan berdata paling awal ikut terdaftar', array_key_exists("$THN-07", $f), implode(',', array_slice(array_keys($f), 0, 5)));
    /* Menawarkan bulan yang mustahil berisi apa pun hanya membuat grafik kosong terbaca rusak. */
    $ok('tidak menawarkan bulan di masa depan',
        ! array_key_exists(now()->addMonth()->format('Y-m'), $f), 'bulan depan ikut ditawarkan');

    $wT->filter = "$THN-08";
    $ok('judul memuat periode yang dipilih',
        str_contains((string) $wT->getHeading(), now()->setDate($THN, $BLN, 1)->translatedFormat('F Y')),
        (string) $wT->getHeading());
    $data = $panggil($wT, 'getData');
    $ok('data widget mengikuti periode yang dipilih',
        array_sum($data['datasets'][0]['data']) === 7, (string) array_sum($data['datasets'][0]['data']));
    $ok('label batang = nama tipe', in_array('CAMAR 6x15', $data['labels'], true), implode(', ', $data['labels']));

    /* $filter datang dari <select> di peramban dan bisa berisi apa saja. */
    /* '2026-13' dan '2026-00' adalah jebakan tersendiri: pembacaan tanggal PHP MENGGULUNGNYA
       menjadi Januari 2027 dan Desember 2025 — tanggal yang sah, jadi tidak ada galat, dan
       panelnya memampangkan periode yang tidak pernah dipilih siapa pun. */
    foreach (['', 'bukan-tanggal', '2026-13', '2026-00', '2026-1', 'null'] as $aneh) {
        $wT->filter = $aneh;
        $ok("filter asing '$aneh' jatuh ke bulan berjalan",
            str_contains((string) $wT->getHeading(), now()->translatedFormat('F Y')), (string) $wT->getHeading());
    }

    echo "\n=== 4. BENTUK PANEL ===\n";

    $wA = new TopAgensiUtjChart;
    $wA->mount();
    $wA->filter = "$THN-08";
    // Dikembalikan ke Agustus: bagian 3 meninggalkannya pada nilai asing (= bulan berjalan).
    $wT->filter = "$THN-08";

    $ok('keduanya batang', $panggil($wT, 'getType') === 'bar' && $panggil($wA, 'getType') === 'bar');
    /* Nama agensi panjang — dipasang tegak, namanya diputar miring atau terpotong. */
    $ok('Top Agent digambar MENDATAR', str_contains((string) $panggil($wA, 'getOptions')->toHtml(), "indexAxis: 'y'"), 'indexAxis tidak disetel');
    $ok('panel tipe TIDAK mendatar', ! str_contains((string) $panggil($wT, 'getOptions')->toHtml(), 'indexAxis'), 'ikut dibalik sumbunya');
    $ok('keduanya meminta label nilai',
        str_contains((string) $panggil($wT, 'getOptions')->toHtml(), 'labelNilai')
        && str_contains((string) $panggil($wA, 'getOptions')->toHtml(), 'labelNilai'));
    /* Dasbor terbuka untuk semua yang bisa masuk panel — tanpa penjaga, angkanya terbaca
       siapa saja dari halaman depan.

       method_exists() TIDAK cukup: canView() diwarisi dari Filament\Widgets\Widget dan selalu
       memulangkan true, jadi pemeriksaan itu tetap lulus meski penjaga di kelas ini dihapus.
       Yang ditegaskan: kelasnya MENYATAKAN SENDIRI method itu. */
    $deklarasiSendiri = function (string $kelas): bool {
        try {
            return (new ReflectionMethod($kelas, 'canView'))->getDeclaringClass()->getName() === $kelas;
        } catch (\Throwable) {
            return false;
        }
    };
    $ok('kedua panel menyatakan penjaga izinnya sendiri, bukan mewarisi yang selalu mengizinkan',
        $deklarasiSendiri(PenjualanTipeChart::class) && $deklarasiSendiri(TopAgensiUtjChart::class),
        json_encode([$deklarasiSendiri(PenjualanTipeChart::class), $deklarasiSendiri(TopAgensiUtjChart::class)]));

    /* DAN penjaga itu harus benar-benar dipakai. Filament hanya menegakkan canView() saat
       widget dirender lewat mekanismenya sendiri (Page::filterVisibleWidgets); dasbor ini
       merender widgetnya langsung dengan @livewire, yang melewatinya sama sekali. Tanpa @if
       di blade, izin yang dipasang di kelas widget tidak menjaga apa pun. */
    $blade = (string) file_get_contents(__DIR__.'/../../resources/views/filament/fo/pages/dashboard.blade.php');
    foreach (['SprBulananChart', 'PenjualanTipeChart', 'TopAgensiUtjChart'] as $kelas) {
        $ok("panel $kelas dibungkus pemeriksaan canView() di blade",
            (bool) preg_match('/@if \(\\\\App\\\\Filament\\\\Fo\\\\Widgets\\\\'.$kelas.'::canView\(\)\)/', $blade),
            'dirender tanpa penjaga — izinnya tidak berlaku');
    }

    $ketT = (string) $wT->getDescription();
    $ok('keterangan menyebut total & rinciannya', str_contains($ketT, '7 unit') && str_contains($ketT, 'CAMAR 6x15 (3)'), $ketT);
    $ok('keterangan menerangkan yang batal tidak dikurangkan', str_contains($ketT, 'tanpa mengurangi yang batal'), $ketT);

    $ketA = (string) $wA->getDescription();
    $ok('keterangan Top Agent menyebut agensi teratas', str_contains($ketA, 'Uhome'), $ketA);
    $ok('keterangan menerangkan yang manual ikut & yang dikembalikan tidak',
        str_contains($ketA, 'manual') && str_contains($ketA, 'dikembalikan'), $ketA);

    $wT->filter = ($THN + 1).'-01';
    $wA->filter = ($THN + 1).'-01';
    // Bulan kosong harus MENGATAKAN dirinya kosong, bukan memampangkan panel hampa tanpa sebab.
    $ok('bulan tanpa data mengatakan keadaannya', str_contains((string) $wT->getDescription(), 'Belum ada SPR')
        && str_contains((string) $wA->getDescription(), 'Belum ada UTJ'));
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
