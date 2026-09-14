<?php

declare(strict_types=1);

/*
 * Uji grafik "Sales Report — SPR per Bulan" pada dashboard Front Office beserta angkanya.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. NETT = GROSS − BATAL DI SETIAP BULAN. Ketiga deret pada tampilan Detail harus saling
 *      menjelaskan di tiap kolom. Bila tidak, pembaca yang menjumlah sendiri menemukan
 *      selisih yang tidak dijelaskan di mana pun — dan angka laporan penjualan yang tidak
 *      bisa dijumlahkan sendiri adalah angka yang berhenti dipercaya.
 *   2. HANYA TAHAP 'SPR'. Enum tahap memuat SPRS (Surat Pemesanan Rumah Sementara, tahap
 *      awal). Ikut menghitungnya membesarkan SELURUH angka laporan tanpa ada yang menyadari.
 *   3. BULAN YANG BELUM BERJALAN DIKOSONGKAN, BUKAN NOL. Batang nol di bulan depan membuat
 *      tren terbaca anjlok padahal bulannya memang belum terjadi.
 *   4. GARIS TARGET TETAP DUA BELAS BULAN walau realisasinya dipotong di bulan berjalan.
 *      Target adalah rencana setahun; memotongnya menghapus justru bagian yang dipakai orang
 *      untuk memperkirakan sisa tahun.
 *   5. IMPOR ULANG MEMPERBARUI, BUKAN MENUMPUK. Skenario yang diunggah dua kali tidak boleh
 *      menjadi dua garis kembar yang saling menimpa di grafik.
 *   6. KETIGA TAMPILAN BERBAGI SATU TIPE & SATU OBJEK OPSI. Saat dropdown diganti, Filament
 *      HANYA mengirim ulang `data` — `options` dan `type` dibekukan sejak render pertama.
 *      Opsi yang berbeda antar tampilan tidak akan pernah sampai ke layar, dan bedanya tidak
 *      menimbulkan galat apa pun: ia hanya diam-diam tidak berlaku.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan (wadah sekali pakai berisi kode kerja, lihat tests/Manual/README.md):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php tests/Manual/uji-spr-bulanan.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Filament\Fo\Widgets\SprBulananChart;
use App\Modules\FO\MarketingSales\Imports\SprTargetImport;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\SprTarget;
use App\Modules\FO\MarketingSales\Services\SprBulananService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal): void {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$TENANT = 1;
$TAHUN  = (int) now()->year;
$svc    = app(SprBulananService::class);

/** Baca method non-publik widget tanpa membocorkannya ke API produksi. */
$panggil = function (SprBulananChart $w, string $metode) {
    $m = new ReflectionMethod($w, $metode);
    $m->setAccessible(true);

    return $m->invoke($w);
};
$dataWidget = function (string $filter) use ($panggil): array {
    $w = new SprBulananChart;
    $w->filter = $filter;

    return $panggil($w, 'getData');
};
/** @param array<int,int> $nilai */
$barisTarget = function (string $skenario, array $nilai, int $tahun): Collection {
    $baris = ['tahun' => $tahun, 'skenario' => $skenario];
    foreach (['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'ags', 'sep', 'okt', 'nov', 'des'] as $i => $k) {
        $baris[$k] = $nilai[$i] ?? 0;
    }

    return new Collection($baris);
};

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seq = 0;
    /* Ditulis lewat query builder, bukan Kontrak::create(): yang diuji di sini angkanya,
       bukan alur pembuatan kontrak beserta event & listener-nya. */
    $kontrak = function (string $tgl, string $status = 'AKTIF', string $tahap = 'SPR', bool $terhapus = false) use (&$seq, $TENANT): int {
        $seq++;
        $id = 890000 + $seq;
        Kontrak::query()->insert([
            'id' => $id,
            'tenant_id' => $TENANT, 'no_kontrak' => 'UJI-SPR-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'customer_id' => 900000 + $seq, 'unit_id' => 900000 + $seq, 'tgl_kontrak' => $tgl,
            'harga_jual' => 0, 'diskon' => 0, 'ppn' => 0, 'total_kontrak' => 0, 'cara_bayar' => 'CASH',
            'status' => $status, 'tahap' => $tahap,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $terhapus ? now() : null,
        ]);

        return $id;
    };

    $seqA = 0;
    /**
     * Baris approval pembatalan. `tglPembatalan` null meniru approval yang lahir dari form
     * generik (KeyValue bebas) yang memang tidak punya kolom tanggal.
     */
    $approval = function (int $kontrakId, string $status, ?string $tglPembatalan, ?string $executedAt = null,
        ?string $approvedAt = null, bool $terhapus = false, ?string $createdAt = null,
        string $jenis = 'BATAL_KONTRAK', ?string $subjectType = null) use (&$seqA, $TENANT): void {
        $seqA++;
        DB::table('fo_approvals')->insert([
            'id' => 880000 + $seqA, 'tenant_id' => $TENANT,
            'no_approval' => 'UJI-APR-'.str_pad((string) $seqA, 5, '0', STR_PAD_LEFT),
            // tgl_approval = tanggal PENGAJUAN. SELALU diisi tahun 2000 pada data uji, supaya
            // kalau ia sampai terpakai sebagai tanggal batal, pembatalannya langsung lenyap dari
            // tahun uji dan ketahuan.
            'tgl_approval' => '2000-01-01',
            'jenis' => $jenis, 'subject_type' => $subjectType ?? Kontrak::class, 'subject_id' => $kontrakId,
            'alasan' => 'uji', 'status' => $status,
            'detail_baru' => json_encode(array_filter([
                'status_baru' => 'BATAL',
                'tgl_pembatalan' => $tglPembatalan,
            ], fn ($v) => $v !== null)),
            'current_level' => 2,
            'executed_at' => $executedAt, 'approved_at' => $approvedAt,
            'created_at' => $createdAt ?? $approvedAt ?? $executedAt ?? now(), 'updated_at' => now(),
            'deleted_at' => $terhapus ? now() : null,
        ]);
    };

    /* Basis data uji ini TIDAK kosong — sebuah migrasi menyemai satu kontrak. Karena itu yang
       ditegaskan di sini SELISIHNYA, bukan angka mutlaknya: angka mutlak akan berubah setiap
       kali ada data lain yang ikut tercatat, dan ujinya lalu merah karena sebab yang tidak ada
       hubungannya dengan yang sedang diuji. */
    $awal     = $svc->perBulan($TAHUN);
    $awalLalu = $svc->perBulan($TAHUN - 1);
    $naik     = fn (array $kini, array $mula, string $jenis, int $bln) => $kini[$jenis][$bln] - $mula[$jenis][$bln];

    echo "\n=== 1. GROSS / BATAL / NETT PER BULAN ===\n";

    // Februari: 3 SPR, 1 batal → +3 / +1 / +2.   April: 2 SPR, dua-duanya batal → +2 / +2 / 0.
    $kontrak("$TAHUN-02-03");
    $kontrak("$TAHUN-02-11", 'BATAL');
    $kontrak("$TAHUN-02-27", 'LUNAS');
    $kontrak("$TAHUN-04-01", 'BATAL');
    $kontrak("$TAHUN-04-30", 'BATAL');

    $p = $svc->perBulan($TAHUN);
    $ok('gross Februari bertambah 3', $naik($p, $awal, 'gross', 2) === 3, (string) $naik($p, $awal, 'gross', 2));
    $ok('batal Februari bertambah 1', $naik($p, $awal, 'batal', 2) === 1, (string) $naik($p, $awal, 'batal', 2));
    $ok('nett Februari bertambah 2', $naik($p, $awal, 'nett', 2) === 2, (string) $naik($p, $awal, 'nett', 2));
    $ok('seluruhnya batal → nett tidak bertambah sama sekali',
        $naik($p, $awal, 'gross', 4) === 2 && $naik($p, $awal, 'nett', 4) === 0,
        json_encode([$naik($p, $awal, 'gross', 4), $naik($p, $awal, 'nett', 4)]));
    $ok('bulan tanpa kontrak baru tidak ikut bergerak', $naik($p, $awal, 'gross', 3) === 0);

    /* Inti nomor 1 — berlaku untuk SETIAP bulan, bukan hanya yang diisi data uji. */
    $selisih = [];
    for ($m = 1; $m <= 12; $m++) {
        if ($p['nett'][$m] !== $p['gross'][$m] - $p['batal'][$m]) { $selisih[] = $m; }
    }
    $ok('nett = gross − batal di kedua belas bulan', $selisih === [], 'meleset di bulan '.implode(',', $selisih));

    echo "\n=== 2. YANG TIDAK IKUT DIHITUNG ===\n";

    $kontrak("$TAHUN-05-05", 'AKTIF', 'SPR');
    $kontrak("$TAHUN-05-06", 'AKTIF', 'SPRS');
    $kontrak("$TAHUN-05-07", 'BATAL', 'SPRS');
    $kontrak("$TAHUN-06-10");
    $kontrak("$TAHUN-06-11", 'AKTIF', 'SPR', true);
    $kontrak(($TAHUN - 1).'-06-12');

    $p = $svc->perBulan($TAHUN);
    // Tiga kontrak ditambahkan di Mei, hanya SATU bertahap SPR.
    $ok('SPRS tidak dihitung sebagai SPR', $naik($p, $awal, 'gross', 5) === 1, (string) $naik($p, $awal, 'gross', 5));
    $ok('pembatalan SPRS tidak ikut terhitung', $naik($p, $awal, 'batal', 5) === 0, (string) $naik($p, $awal, 'batal', 5));
    // Dua kontrak ditambahkan di Juni, satu di antaranya sudah dihapus.
    $ok('kontrak ter-soft-delete tidak dihitung', $naik($p, $awal, 'gross', 6) === 1, (string) $naik($p, $awal, 'gross', 6));
    $ok('tahun sebelumnya berdiri sendiri',
        $naik($svc->perBulan($TAHUN - 1), $awalLalu, 'gross', 6) === 1);

    echo "\n=== 2b. BULAN BATAL MENGIKUTI TANGGAL PEMBATALANNYA ===\n";

    $mula = $svc->perBulan($TAHUN);
    $mulaLalu = $svc->perBulan($TAHUN - 1);

    /* Dipesan Juli, dibatalkan Oktober. Gross jatuh di Juli, Batal di Oktober — dua kelompok
       kontrak yang berbeda, dan itu memang yang diminta. */
    $a1 = $kontrak("$TAHUN-07-04", 'BATAL');
    $approval($a1, 'EXECUTED', "$TAHUN-10-09", "$TAHUN-10-10 08:00:00");

    $p = $svc->perBulan($TAHUN);
    $ok('gross tetap di bulan kontraknya', $naik($p, $mula, 'gross', 7) === 1, (string) $naik($p, $mula, 'gross', 7));
    $ok('batal pindah ke bulan pembatalannya', $naik($p, $mula, 'batal', 10) === 1, (string) $naik($p, $mula, 'batal', 10));
    $ok('bulan kontraknya TIDAK ikut dihitung sebagai batal', $naik($p, $mula, 'batal', 7) === 0, (string) $naik($p, $mula, 'batal', 7));
    /* Akibat yang disengaja: bulan pembatalan bisa bernilai negatif. */
    $ok('nett bulan pembatalan berkurang, bisa negatif', $naik($p, $mula, 'nett', 10) === -1, (string) $naik($p, $mula, 'nett', 10));

    /* Dipesan TAHUN LALU, dibatalkan tahun ini → masuk Batal tahun ini, tanpa menambah Gross. */
    $a2 = $kontrak(($TAHUN - 1).'-11-02', 'BATAL');
    $approval($a2, 'EXECUTED', "$TAHUN-03-15", "$TAHUN-03-16 08:00:00");

    $p = $svc->perBulan($TAHUN);
    $ok('pembatalan atas kontrak tahun lalu masuk ke tahun ini',
        $naik($p, $mula, 'batal', 3) === 1, (string) $naik($p, $mula, 'batal', 3));
    $ok('tanpa menambah gross tahun ini', $naik($p, $mula, 'gross', 3) === 0, (string) $naik($p, $mula, 'gross', 3));
    $ok('dan tidak lagi terhitung di tahun kontraknya',
        $naik($svc->perBulan($TAHUN - 1), $mulaLalu, 'batal', 11) === 0,
        (string) $naik($svc->perBulan($TAHUN - 1), $mulaLalu, 'batal', 11));

    /* Approval yang DITOLAK tetap menyimpan tgl_pembatalan sejak baris dibuat. Memakainya
       berarti mencatat pembatalan yang tidak pernah terjadi lewat baris itu. */
    $a3 = $kontrak("$TAHUN-08-05", 'BATAL');
    $approval($a3, 'REJECTED', "$TAHUN-11-20", null, "$TAHUN-11-21 08:00:00");
    /* Approval JENIS LAIN atas kontrak yang sama (mis. diskon yang pernah diajukan), dan
       approval milik JENIS SUBJEK LAIN yang kebetulan ber-id sama. Keduanya EXECUTED dan
       bertanggal November — kalau penyaring jenis atau subject_type lepas, batalnya pindah
       ke November dan Agustus kehilangan miliknya. */
    $approval($a3, 'EXECUTED', "$TAHUN-11-22", "$TAHUN-11-23 08:00:00", null, false, null, 'DISKON');
    $approval($a3, 'EXECUTED', "$TAHUN-11-24", "$TAHUN-11-25 08:00:00", null, false, null, 'BATAL_KONTRAK',
        'App\\Modules\\FO\\Launching\\Models\\NupRegistration');

    $p = $svc->perBulan($TAHUN);
    $ok('approval DITOLAK tidak dipakai sebagai tanggal batal',
        $naik($p, $mula, 'batal', 11) === 0, (string) $naik($p, $mula, 'batal', 11));
    $ok('jatuh ke bulan kontraknya ketika tidak ada approval yang sah',
        $naik($p, $mula, 'batal', 8) === 1, (string) $naik($p, $mula, 'batal', 8));

    /* Ditolak lalu diajukan ulang: tiga baris, satu pembatalan. Sengaja TIDAK di bulan yang
       gross-nya sudah ditegaskan bagian lain, supaya penambahan data uji di sini tidak
       diam-diam mengubah arti pemeriksaan di bagian lain. */
    $a4 = $kontrak("$TAHUN-09-02", 'BATAL');
    $approval($a4, 'REJECTED', "$TAHUN-05-01", null, "$TAHUN-05-02 08:00:00");
    $approval($a4, 'EXECUTED', "$TAHUN-06-03", "$TAHUN-06-04 08:00:00");
    $approval($a4, 'EXECUTED', "$TAHUN-09-07", "$TAHUN-09-08 08:00:00");

    $p = $svc->perBulan($TAHUN);
    $ok('beberapa approval untuk satu kontrak tetap satu pembatalan',
        $naik($p, $mula, 'batal', 9) === 1 && $naik($p, $mula, 'batal', 6) === 0,
        json_encode([$naik($p, $mula, 'batal', 9), $naik($p, $mula, 'batal', 6)]));

    /* Approval tanpa tgl_pembatalan (form generik) → jatuh ke executed_at. */
    $a5 = $kontrak("$TAHUN-01-10", 'BATAL');
    $approval($a5, 'EXECUTED', null, "$TAHUN-12-05 08:00:00");

    /* tgl_pembatalan berbentuk lain → jatuh ke executed_at. Sengaja dipilih tanggal yang MASIH
       BISA dibaca pembaca longgar (05/07 → 5 Juli atau 7 Mei, tergantung tafsir) dan bukan yang
       pasti gagal: pembacaan longgar tidak melempar galat, ia memulangkan tanggal yang salah
       tanpa keluhan — dan itu kegagalan yang jauh lebih sulit terlihat. */
    $a6 = $kontrak("$TAHUN-01-11", 'BATAL');
    $approval($a6, 'EXECUTED', "05/07/$TAHUN", "$TAHUN-12-06 08:00:00");

    $p = $svc->perBulan($TAHUN);
    $ok('approval tanpa tgl_pembatalan jatuh ke executed_at',
        $naik($p, $mula, 'batal', 12) === 2, (string) $naik($p, $mula, 'batal', 12));
    /* Inti: tgl_approval (tanggal PENGAJUAN, di data uji sengaja tahun 2000) tidak boleh
       pernah terpakai — kalau terpakai, batalnya lenyap dari tahun ini sama sekali. */
    $ok('tgl_approval tidak pernah dipakai sebagai tanggal batal',
        array_sum($p['batal']) - array_sum($mula['batal']) === 6,
        'jumlah batal tahun ini bertambah '.(array_sum($p['batal']) - array_sum($mula['batal'])).', seharusnya 6');

    /* Approval ter-soft-delete tetap merekam KAPAN pembatalannya terjadi. */
    $a7 = $kontrak("$TAHUN-01-12", 'BATAL');
    $approval($a7, 'EXECUTED', "$TAHUN-05-20", "$TAHUN-05-21 08:00:00", null, true);

    $p = $svc->perBulan($TAHUN);
    $ok('approval yang sudah dihapus tetap dipakai tanggalnya',
        $naik($p, $mula, 'batal', 5) === 1, (string) $naik($p, $mula, 'batal', 5));

    /* Approval EXECUTED tanpa executed_at MAUPUN approved_at — lahir dari koreksi manual super
       admin. Satu-satunya tanggal yang tersisa adalah created_at. Ini yang membuktikan
       tgl_approval (tahun 2000) tidak dipakai sebagai cadangan terakhir. */
    $a8 = $kontrak("$TAHUN-01-13", 'BATAL');
    $approval($a8, 'EXECUTED', null, null, null, false, "$TAHUN-04-18 08:00:00");

    $p = $svc->perBulan($TAHUN);
    $ok('tanpa executed_at & approved_at, jatuh ke created_at — bukan ke tgl_approval',
        $naik($p, $mula, 'batal', 4) === 1, (string) $naik($p, $mula, 'batal', 4));

    /* tgl_pembatalan diketik pemohon, jadi bisa dimajukan ke tahun depan. Pembatalan itu bukan
       milik tahun ini dan tidak boleh muncul di kolom mana pun tahun ini. */
    $a9 = $kontrak("$TAHUN-01-14", 'BATAL');
    $approval($a9, 'EXECUTED', ($TAHUN + 1).'-02-09', ($TAHUN + 1).'-02-10 08:00:00');

    $p = $svc->perBulan($TAHUN);
    // Delapan pembatalan jatuh di tahun ini (a1..a8); a9 bertanggal tahun depan dan tidak ikut.
    $ok('pembatalan bertanggal TAHUN DEPAN tidak masuk tahun ini sama sekali',
        array_sum($p['batal']) - array_sum($mula['batal']) === 8,
        'jumlah batal tahun ini '.(array_sum($p['batal']) - array_sum($mula['batal'])).', seharusnya 8');
    $ok('tetapi kontraknya tetap terhitung sebagai gross tahun ini',
        $naik($p, $mula, 'gross', 1) === 5, (string) $naik($p, $mula, 'gross', 1));

    echo "\n=== 3. BULAN BERJALAN & PEMOTONGAN DERET ===\n";

    $kini = Carbon::parse("$TAHUN-09-13");
    $ok('tahun berjalan → sampai bulan ini', $svc->bulanBerjalan($TAHUN, $kini) === 9);
    $ok('tahun lampau → dua belas bulan penuh', $svc->bulanBerjalan($TAHUN - 1, $kini) === 12);
    $ok('tahun depan → belum ada apa-apa', $svc->bulanBerjalan($TAHUN + 1, $kini) === 0);

    $deret = $svc->hinggaBulan(array_fill(1, 12, 7), 3);
    $ok('bulan yang sudah berjalan berisi nilainya', $deret[3] === 7);
    /* Inti nomor 3: null, BUKAN 0. */
    $ok('bulan yang belum berjalan KOSONG, bukan nol', $deret[4] === null && $deret[12] === null,
        json_encode([$deret[4], $deret[12]]));
    $ok('panjang deret tetap dua belas agar sejajar label bulan', count($deret) === 12, (string) count($deret));

    echo "\n=== 4. IMPOR TARGET ===\n";

    $imp = new SprTargetImport($TENANT);
    $imp->collection(new Collection([
        $barisTarget('Target (WPnB)', [100, 73, 45, 108, 87, 81, 33, 80, 65, 64, 60, 33], $TAHUN),
        $barisTarget('Adjust 1 (4&5 Feb 26)', [58, 73, 57, 85, 87, 81, 33, 95, 95, 64, 60, 10], $TAHUN),
    ]));
    $ok('dua skenario terbaca tanpa catatan', $imp->errors === [] && $imp->created === 2,
        $imp->created.' dibuat; '.implode(' | ', $imp->errors));

    $t = $svc->target($TAHUN);
    $ok('urutan legenda mengikuti urutan baris berkas, bukan abjad',
        ($t[0]['skenario'] ?? '') === 'Target (WPnB)', (string) ($t[0]['skenario'] ?? '?'));
    $ok('nilai Januari & Desember terbaca', ($t[0]['bulan'][1] ?? 0) === 100 && ($t[0]['bulan'][12] ?? 0) === 33);
    $ok('jumlah setahun = jumlah kedua belas bulannya', ($t[0]['setahun'] ?? 0) === 829, (string) ($t[0]['setahun'] ?? 0));

    $lagi = new SprTargetImport($TENANT);
    $lagi->collection(new Collection([$barisTarget('Target (WPnB)', [99], $TAHUN)]));
    /* Inti nomor 5. */
    $ok('impor ulang MEMPERBARUI, bukan menumpuk', $lagi->updated === 1 && $lagi->created === 0,
        $lagi->created.' dibuat / '.$lagi->updated.' diperbarui');
    $ok('tetap satu baris per skenario',
        SprTarget::query()->withoutGlobalScopes()->where('tenant_id', $TENANT)->where('tahun', $TAHUN)->where('skenario', 'Target (WPnB)')->count() === 1);
    $ok('angkanya yang berubah, bukan barisnya', ($svc->target($TAHUN)[0]['bulan'][1] ?? 0) === 99);

    $ejaan = new SprTargetImport($TENANT);
    $ejaan->collection(new Collection([
        new Collection(['tahun' => $TAHUN, 'skenario' => 'Ejaan Panjang', 'januari' => 5, 'agustus' => 8, 'desember' => 12]),
    ]));
    /* Mengunggah SATU skenario saja adalah hal yang wajar (satu revisi diperbaiki). Itu tidak
       boleh mengubah susunan legenda: kalau urutan ikut ditulis ulang, skenario yang baru
       diunggah melompat ke depan dan keterangan di bawah judul menyebut target induk yang
       salah — tanpa galat apa pun. */
    $ok('impor sebagian TIDAK mengacak urutan legenda',
        array_column($svc->target($TAHUN), 'skenario') === ['Target (WPnB)', 'Adjust 1 (4&5 Feb 26)', 'Ejaan Panjang'],
        implode(' | ', array_column($svc->target($TAHUN), 'skenario')));

    /* Sengaja yang diimpor ulang adalah skenario KEDUA, bukan yang pertama. Mengimpor ulang
       skenario yang urutannya memang sudah 1 tidak membuktikan apa-apa: urutannya tetap 1
       entah kode mempertahankannya atau menulisnya ulang. Barulah skenario kedua yang
       melompat ke depan memperlihatkan bedanya. */
    $ulangKedua = new SprTargetImport($TENANT);
    $ulangKedua->collection(new Collection([$barisTarget('Adjust 1 (4&5 Feb 26)', [7], $TAHUN)]));
    $ok('skenario kedua yang diimpor ulang tidak melompat ke urutan pertama',
        array_column($svc->target($TAHUN), 'skenario') === ['Target (WPnB)', 'Adjust 1 (4&5 Feb 26)', 'Ejaan Panjang'],
        implode(' | ', array_column($svc->target($TAHUN), 'skenario')));
    $ok('angkanya tetap ikut diperbarui', $ulangKedua->updated === 1
        && (collect($svc->target($TAHUN))->firstWhere('skenario', 'Adjust 1 (4&5 Feb 26)')['bulan'][1] ?? 0) === 7);

    $panjang = collect($svc->target($TAHUN))->firstWhere('skenario', 'Ejaan Panjang');
    $ok('ejaan bulan panjang dikenali (Januari/Agustus/Desember)',
        ($panjang['bulan'][1] ?? 0) === 5 && ($panjang['bulan'][8] ?? 0) === 8 && ($panjang['bulan'][12] ?? 0) === 12,
        json_encode([$panjang['bulan'][1] ?? null, $panjang['bulan'][8] ?? null, $panjang['bulan'][12] ?? null]));
    /* Kolom bulan yang hilang tersimpan 0 — dan di grafik 0 karena kolom hilang tidak bisa
       dibedakan dari target 0 yang disengaja. Karena itu wajib diberitahukan. */
    $ok('kolom bulan yang tidak lengkap dilaporkan', $ejaan->errors !== [], 'tidak dilaporkan');

    $tolak = new SprTargetImport($TENANT);
    $tolak->collection(new Collection([
        $barisTarget('', [1], $TAHUN),
        (new Collection(['tahun' => 0, 'skenario' => 'Tanpa tahun', 'jan' => 1])),
        new Collection(['tahun' => $TAHUN, 'skenario' => 'Tanpa bulan']),
        new Collection(['tahun' => null, 'skenario' => null]),
    ]));
    $ok('tiga baris bermasalah ditolak', $tolak->skipped === 3 && $tolak->created === 0,
        $tolak->skipped.' dilewati / '.$tolak->created.' dibuat');
    $ok('baris kosong di ujung sheet TIDAK dianggap galat', count($tolak->errors) === 3, count($tolak->errors).' catatan');

    echo "\n=== 5. DROPDOWN & KETIGA TAMPILAN ===\n";

    $filters = $panggil(new SprBulananChart, 'getFilters');
    $ok('dropdown memuat tepat tiga tampilan', array_keys($filters) === ['target', 'banding', 'detail'],
        implode(', ', array_keys($filters)));
    $ok('tampilan banding menyebut kedua tahunnya',
        str_contains($filters['banding'], (string) $TAHUN) && str_contains($filters['banding'], (string) ($TAHUN - 1)),
        $filters['banding']);

    $d = $dataWidget('detail');
    $ok('label bulan dua belas', count($d['labels']) === 12, (string) count($d['labels']));
    $ok('tampilan Detail memuat tiga deret', count($d['datasets']) === 3, (string) count($d['datasets']));
    $ok('Gross/Nett/Batal Februari bertambah 3/2/1',
        $d['datasets'][0]['data'][1] - $awal['gross'][2] === 3
        && $d['datasets'][1]['data'][1] - $awal['nett'][2] === 2
        && $d['datasets'][2]['data'][1] - $awal['batal'][2] === 1,
        json_encode([$d['datasets'][0]['data'][1], $d['datasets'][1]['data'][1], $d['datasets'][2]['data'][1]]));
    $ok('jumlah setahun ditempel pada nama deret di legenda',
        str_contains((string) $d['datasets'][0]['label'], 'Σ '), (string) $d['datasets'][0]['label']);
    $ok('ketiganya batang', array_column($d['datasets'], 'type') === ['bar', 'bar', 'bar'],
        implode(',', array_column($d['datasets'], 'type')));

    $dt = $dataWidget('target');
    $ok('tampilan Target: satu batang realisasi + tiga garis target',
        count($dt['datasets']) === 4, (string) count($dt['datasets']));
    $ok('realisasi digambar batang, target digambar garis',
        $dt['datasets'][0]['type'] === 'bar' && $dt['datasets'][1]['type'] === 'line',
        $dt['datasets'][0]['type'].'/'.($dt['datasets'][1]['type'] ?? '?'));
    /* Inti nomor 4. */
    $ok('garis target TIDAK ikut terpotong di bulan berjalan',
        ! in_array(null, $dt['datasets'][1]['data'], true), 'ada bulan yang kosong');
    $bulanIni = (int) now()->month;
    $ok('realisasi berhenti di bulan berjalan',
        $bulanIni >= 12 || $dt['datasets'][0]['data'][$bulanIni] === null,
        'bulan sesudahnya tidak dikosongkan');
    $ok('garis target pertama utuh, revisinya putus-putus',
        ($dt['datasets'][1]['borderDash'] ?? null) === [] && ($dt['datasets'][2]['borderDash'] ?? null) !== [],
        json_encode([$dt['datasets'][1]['borderDash'] ?? null, $dt['datasets'][2]['borderDash'] ?? null]));

    $kontrak(($TAHUN - 1).'-12-31');
    $db = $dataWidget('banding');
    $ok('tampilan Banding memuat dua tahun', count($db['datasets']) === 2, (string) count($db['datasets']));
    $ok('tahun lalu SELALU dua belas bulan penuh',
        ! in_array(null, $db['datasets'][1]['data'], true)
        && $db['datasets'][1]['data'][11] - $awalLalu['nett'][12] === 1,
        json_encode($db['datasets'][1]['data']));

    echo "\n=== 6. TIPE & OPSI SAMA UNTUK KETIGA TAMPILAN ===\n";

    /* Inti nomor 6: Filament hanya mengirim ulang `data` saat dropdown diganti. Opsi yang
       berbeda antar tampilan tidak akan pernah berlaku — dan diamnya tidak menimbulkan galat. */
    $w = new SprBulananChart;
    $tipe = [];
    $opsi = [];
    foreach (['target', 'banding', 'detail'] as $f) {
        $w->filter = $f;
        $tipe[] = $panggil($w, 'getType');
        $opsi[] = (string) $panggil($w, 'getOptions')->toHtml();
    }
    $ok('tipe dasar sama untuk ketiganya', $tipe === ['bar', 'bar', 'bar'], implode(',', $tipe));
    $ok('objek opsi persis sama untuk ketiganya', count(array_unique($opsi)) === 1, 'ada opsi yang berbeda antar tampilan');
    $ok('penanda plugin label nilai ada di opsi', str_contains($opsi[0], 'labelNilai'), 'penanda hilang');

    echo "\n=== 7. KETERANGAN DI BAWAH JUDUL ===\n";

    $ket = (string) $panggil(new SprBulananChart, 'getDescription');
    /* Angkanya 99 karena uji impor-ulang di bagian 4 memperbarui "Target (WPnB)" menjadi
       Januari 99 dan sisanya nol. Yang ditegaskan di sini: keterangan memakai skenario
       PERTAMA sebagai target induk, dan angkanya benar-benar dibaca dari baris itu — bukan
       dari skenario lain yang kebetulan lebih besar. */
    $ok('menyebut target tahunan dari skenario pertama, bukan yang lain',
        str_contains($ket, '99 unit') && str_contains($ket, 'Target (WPnB)'), $ket);
    $ok('menerangkan definisi SPR & Nett', str_contains($ket, 'Nett = Gross − Batal'), $ket);
    $ok('menyatakan SPRS tidak ikut dihitung', str_contains($ket, '(tanpa SPRS)'), $ket);
    /* Tanpa kalimat ini, batang Nett yang turun ke bawah nol terbaca sebagai kerusakan —
       padahal itu justru keadaan yang benar ketika pembatalan bulan itu melebihi SPR barunya. */
    $ok('memberi tahu Batal mengikuti bulan pembatalannya',
        str_contains($ket, 'bulan pembatalannya') && str_contains($ket, 'bisa negatif'), $ket);
    $ok('memberi tahu legenda bisa diklik', str_contains($ket, 'klik legenda'), $ket);

    SprTarget::query()->withoutGlobalScopes()->where('tenant_id', $TENANT)->where('tahun', $TAHUN)->delete();
    $kosong = (string) $panggil(new SprBulananChart, 'getDescription');
    /* Tanpa kalimat ini, tampilan "Target vs Realisasi" tampak rusak — batangnya ada, garisnya
       tidak — dan tidak ada apa pun di layar yang menjelaskan kenapa. */
    $ok('memberi tahu saat target belum diimpor', str_contains($kosong, 'target belum diimpor'), $kosong);
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
