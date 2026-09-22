<?php

declare(strict_types=1);

/**
 * Uji: Kwitansi (BKM) → jadwal tagihan → kolom Collection Cash di Master Data KPR.
 *
 * Layar BO "Master Data KPR" membentuk tiap sel Angsuran Cash dari SEPASANG kolom
 * ipro_bo.payment_schedules: `paid_at` (Tanggal) dan `paid_amount` (Nominal). Sebelum
 * perubahan ini rantainya putus di DUA tempat sekaligus, dan masing-masing cukup
 * sendirian untuk membuat seluruh selnya tampil "—":
 *
 *   (a) TANGGAL tidak punya sumber. fo_kontrak_tagihan sama sekali tidak menyimpan
 *       tanggal bayar — hanya `nilai` & `pelunasan` — sehingga syncSchedules tidak
 *       pernah menulis paid_at. Tanggal uang masuk hanya ada di KWITANSI-nya, dan
 *       kwitansi itu sudah menunjuk baris tagihannya lewat fo_bkm.kontrak_tagihan_id.
 *   (b) NOMINAL membeku. paid_amount memang disalin dari `pelunasan`, tetapi hanya di
 *       dalam syncSpr — yang dulu satu-satunya pemicunya adalah Kontrak::saved.
 *       Menerima pembayaran hanya menyentuh KontrakTagihan dan Bkm, tak pernah Kontrak.
 *
 * BAGIAN A menguji isinya (di dalam transaksi FO, lalu di-rollback).
 * BAGIAN B menguji PEMICUNYA — sengaja DI LUAR transaksi, sebab dispatch job memakai
 * afterCommit dan di dalam transaksi yang di-rollback ia tak akan pernah jalan.
 *
 * Baris yang lahir di ipro_bo TIDAK ikut ter-rollback (beda koneksi) → dibersihkan
 * sendiri di blok finally.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-kwitansi-ke-collection-cash.php
 */

use App\Modules\BO\IproBridge\Jobs\SyncFoToIproJob;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

$bo = DB::connection((string) config('ipro.connection', 'ipro_bo'));
$tenant = (int) config('ipro.tenant_id', 1);
$projectId = (int) config('ipro.project_id', 1);
$sync = app(IproMasterSync::class);

$TAG = 'ZKW'.strtoupper(substr((string) getmypid(), -4));
$NO_SPR = 'UJI-'.$TAG;

/** Baris payment_schedules BO untuk satu label tagihan. */
$jadwal = function (string $label) use ($bo, $tenant, $NO_SPR) {
    $bid = $bo->table('bookings')->where('tenant_id', $tenant)->where('booking_number', $NO_SPR)->value('id');

    return $bid ? $bo->table('payment_schedules')->where('booking_id', $bid)->where('label', $label)->first() : null;
};
/** Sel seperti yang dibaca MasterDataKprController: ['t' => paid_at, 'n' => paid_amount]. */
$sel = function (?object $r) {
    return $r === null ? null : ['t' => $r->paid_at ? substr((string) $r->paid_at, 0, 10) : null, 'n' => (float) $r->paid_amount];
};

echo "\n=== 0. Prasyarat ===\n";
$cek('ipro_bo.payment_schedules punya kolom paid_at',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasColumn('payment_schedules', 'paid_at'));
$adaBoPosted = Schema::hasColumn('fo_bkm', 'bo_posted_at');
$cek('jerp.fo_bkm punya kolom bo_posted_at (penanda "Terkirim")', $adaBoPosted,
    'migrasi 2026_06_30_000070 belum jalan di DB ini — syarat turun ke POSTED saja');
$cek('fo_bkm menunjuk baris tagihan lewat kontrak_tagihan_id', Schema::hasColumn('fo_bkm', 'kontrak_tagihan_id'));

// ─────────────────────────────────────────────────────────────────────────────
// BAGIAN A — isi sel: paid_at & paid_amount
// ─────────────────────────────────────────────────────────────────────────────

DB::beginTransaction();

try {
    $custId = (int) DB::table('fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'code' => 'CUST-'.$TAG,
        'full_name' => 'Pembeli Uji Kwitansi', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (int) DB::table('fo_units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode_unit' => 'UNIT-'.$TAG,
        'cluster' => 'Cluster Uji', 'blok' => 'A', 'no_unit' => '15',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // CASH_BERTAHAP → skema BO bertipe 'cicilan', BUKAN 'kpr'. Itu penting: untuk booking
    // berskema kpr, baris ANG memang SENGAJA dibuang dari Collection Cash (di sana ANG
    // berarti pencairan bank, bukan setoran customer).
    $kontrakId = (int) DB::table('fo_kontrak')->insertGetId([
        'tenant_id' => $tenant, 'no_kontrak' => $NO_SPR, 'customer_id' => $custId, 'unit_id' => $unitId,
        'tgl_kontrak' => '2026-08-01', 'harga_jual' => 562000000, 'total_kontrak' => 562000000,
        'cara_bayar' => 'CASH_BERTAHAP', 'status' => 'AKTIF', 'tahap' => 'SPR',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $tagihan = [];
    foreach ([
        ['BF', 'BOOKING FEE 1', 3000000],
        ['ANG', 'ANGSURAN 1', 187333331],
        ['ANG', 'ANGSURAN 2', 187333331],
        ['ANG', 'ANGSURAN 3', 187333338],
    ] as $u => $row) {
        [$tipe, $nama, $nilai] = $row;
        $tagihan[$nama] = (int) DB::table('fo_kontrak_tagihan')->insertGetId([
            'kontrak_id' => $kontrakId, 'urutan' => $u + 1, 'tipe' => $tipe, 'nama_tagihan' => $nama,
            'jatuh_tempo' => '2026-09-01', 'nilai' => $nilai, 'pelunasan' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Pasangan master di ipro_bo (dicocokkan lewat code / unit_code) — tanpanya syncSpr null.
    $bo->table('customers')->insert([
        'tenant_id' => $tenant, 'code' => 'CUST-'.$TAG, 'full_name' => 'Pembeli Uji Kwitansi',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000002'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bo->table('units')->insert([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'unit_code' => 'UNIT-'.$TAG,
        'base_price' => 562000000, 'current_price' => 562000000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $kontrak = Kontrak::find($kontrakId);

    /** Terbitkan kwitansi persis seperti PembayaranService: pelunasan naik + BKM terbit. */
    $bayar = function (string $namaTagihan, float $nominal, string $tgl, array $opts = []) use ($tagihan, $kontrakId, $custId, $tenant, $TAG, $adaBoPosted) {
        DB::table('fo_kontrak_tagihan')->where('id', $tagihan[$namaTagihan])
            ->update(['pelunasan' => DB::raw('pelunasan + '.$nominal)]);
        $data = [
            'tenant_id' => $tenant, 'no_bkm' => 'BKM-'.$TAG.'-'.substr(md5($namaTagihan.$tgl.$nominal), 0, 6),
            'tgl_bkm' => $tgl, 'customer_id' => $custId, 'kontrak_id' => $kontrakId,
            'kontrak_tagihan_id' => $tagihan[$namaTagihan], 'amount' => $nominal,
            'jenis_pembayaran' => $opts['jenis'] ?? 'ANGSURAN', 'status' => $opts['status'] ?? 'POSTED',
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($adaBoPosted) {
            $data['bo_posted_at'] = ($opts['terkirim'] ?? true) ? now() : null;
        }

        return (int) DB::table('fo_bkm')->insertGetId($data);
    };

    echo "\n=== 1. Sebelum ada pembayaran: nominal nol, tanggal kosong ===\n";

    $bookingId = $sync->syncSpr($kontrak);
    $cek('SPR tersinkron ke ipro_bo', $bookingId !== null);
    $a1 = $jadwal('ANGSURAN 1');
    $cek('  baris ANGSURAN 1 terbentuk', $a1 !== null);
    $cek('  nominalnya nol', (float) ($a1->paid_amount ?? -1) === 0.0);
    // Ditulis lugas, BUKAN ($a1->paid_at ?? 'x') === null: `??` ikut menelan null sehingga
    // bentuk itu tidak pernah bisa benar — penjaganya gagal untuk alasan yang salah.
    $cek('  tanggalnya kosong', $a1 !== null && $a1->paid_at === null, json_encode($a1->paid_at ?? null));

    echo "\n=== 2. Kwitansi POSTED + Terkirim mengisi TANGGAL dan NOMINAL ===\n";

    $bayar('ANGSURAN 1', 187333331, '2026-09-21');
    $sync->syncSpr($kontrak);
    $s = $sel($jadwal('ANGSURAN 1'));
    $cek('Nominal terisi dari pelunasan', ($s['n'] ?? 0) === 187333331.0, json_encode($s));
    // Inilah yang dulu mustahil: tanggalnya tidak punya sumber sama sekali.
    $cek('TANGGAL terisi dari tgl_bkm kwitansinya', ($s['t'] ?? null) === '2026-09-21', json_encode($s));
    $cek('  baris tagihan lain tidak ikut terisi',
        $sel($jadwal('ANGSURAN 2'))['t'] === null && $sel($jadwal('ANGSURAN 3'))['t'] === null);
    $cek('  baris Booking Fee (bukan ANG) juga tak tersentuh', $sel($jadwal('BOOKING FEE 1'))['t'] === null);

    echo "\n=== 3. Syarat pemilik proses: POSTED saja belum cukup ===\n";

    if ($adaBoPosted) {
        $bayar('ANGSURAN 2', 187333331, '2026-09-22', ['terkirim' => false]);
        $sync->syncSpr($kontrak);
        $s2 = $sel($jadwal('ANGSURAN 2'));
        // Uangnya memang sudah masuk (pelunasan naik), tetapi kwitansinya BELUM dikirim ke
        // Approval BO. Tanggalnya sengaja ditahan sampai syaratnya terpenuhi.
        $cek('kwitansi POSTED tapi belum Terkirim: nominal ikut, tanggal DITAHAN',
            ($s2['n'] ?? 0) === 187333331.0 && ($s2['t'] ?? null) === null, json_encode($s2));

        DB::table('fo_bkm')->where('kontrak_tagihan_id', $tagihan['ANGSURAN 2'])->update(['bo_posted_at' => now()]);
        $sync->syncSpr($kontrak);
        $cek('  begitu dikirim (Terkirim), tanggalnya muncul',
            $sel($jadwal('ANGSURAN 2'))['t'] === '2026-09-22', json_encode($sel($jadwal('ANGSURAN 2'))));
    } else {
        echo "  (dilewati — kolom bo_posted_at belum ada di DB ini)\n";
    }

    echo "\n=== 4. Kwitansi BATAL tidak boleh meninggalkan tanggal ===\n";

    $idBatal = $bayar('ANGSURAN 3', 187333338, '2026-09-23');
    $sync->syncSpr($kontrak);
    $cek('sebelum dibatalkan tanggalnya ada', $sel($jadwal('ANGSURAN 3'))['t'] === '2026-09-23');

    // Persis yang dilakukan BkmCancelService: status BATAL + pelunasan dikembalikan.
    DB::table('fo_bkm')->where('id', $idBatal)->update(['status' => 'BATAL']);
    DB::table('fo_kontrak_tagihan')->where('id', $tagihan['ANGSURAN 3'])->update(['pelunasan' => 0]);
    $sync->syncSpr($kontrak);
    $s3 = $sel($jadwal('ANGSURAN 3'));
    $cek('sesudah dibatalkan tanggal DAN nominalnya bersih', $s3['t'] === null && $s3['n'] === 0.0, json_encode($s3));

    echo "\n=== 5. Pembayaran sebagian: tanggal TERAKHIR yang dipakai ===\n";

    // Tagihan ANGSURAN 3 dibayar dua kali. Yang berarti bagi "kapan tertagih" adalah
    // kwitansi terakhir, bukan yang pertama.
    $bayar('ANGSURAN 3', 100000000, '2026-09-24');
    $bayar('ANGSURAN 3', 87333338, '2026-09-27');
    $sync->syncSpr($kontrak);
    $s4 = $sel($jadwal('ANGSURAN 3'));
    $cek('nominalnya jumlah kedua kwitansi', $s4['n'] === 187333338.0, json_encode($s4));
    $cek('tanggalnya kwitansi TERAKHIR, bukan yang pertama', $s4['t'] === '2026-09-27', json_encode($s4));

    echo "\n=== 5b. Satu dari dua kwitansi dibatalkan: tanggal MUNDUR ke yang tersisa ===\n";

    // Kasus yang paling mudah lolos dari penjagaan: uangnya TIDAK habis dibatalkan, jadi
    // pelunasan tetap di atas nol dan selnya tetap terisi. Kalau kwitansi BATAL masih ikut
    // dihitung, tanggalnya akan bertahan pada 27 Sep — tanggal setoran yang sudah dianulir —
    // sementara nominalnya sudah turun. Tidak ada galat apa pun yang memberi tahu.
    DB::table('fo_bkm')->where('kontrak_tagihan_id', $tagihan['ANGSURAN 3'])
        ->where('tgl_bkm', '2026-09-27')->update(['status' => 'BATAL']);
    DB::table('fo_kontrak_tagihan')->where('id', $tagihan['ANGSURAN 3'])->update(['pelunasan' => 100000000]);
    $sync->syncSpr($kontrak);
    $s4b = $sel($jadwal('ANGSURAN 3'));
    $cek('nominalnya turun ke sisa yang sah', $s4b['n'] === 100000000.0, json_encode($s4b));
    $cek('tanggalnya kembali ke kwitansi yang masih sah', $s4b['t'] === '2026-09-24', json_encode($s4b));

    echo "\n=== 6. Tanggal tidak pernah berdiri sendirian di atas nominal nol ===\n";

    // Sel di layar adalah sepasang. Tanggal tanpa nominal terbaca "dibayar Rp 0".
    DB::table('fo_kontrak_tagihan')->where('id', $tagihan['ANGSURAN 3'])->update(['pelunasan' => 0]);
    $sync->syncSpr($kontrak);
    $s5 = $sel($jadwal('ANGSURAN 3'));
    $cek('pelunasan nol → tanggalnya ikut kosong walau kwitansinya masih ada',
        $s5['t'] === null && $s5['n'] === 0.0, json_encode($s5));

    echo "\n=== 7. Diulang tidak merusak: id baris tetap (FK aman) ===\n";

    $idSebelum = $bo->table('payment_schedules')->where('booking_id', $bookingId)->orderBy('sequence')->pluck('id')->all();
    for ($i = 0; $i < 3; $i++) { $sync->syncSpr($kontrak); }
    $idSesudah = $bo->table('payment_schedules')->where('booking_id', $bookingId)->orderBy('sequence')->pluck('id')->all();
    $cek('tiga kali sinkron: id baris jadwal tidak bergerak', $idSebelum === $idSesudah,
        json_encode([$idSebelum, $idSesudah]));
    $cek('  jumlah barisnya tetap empat', count($idSesudah) === 4, (string) count($idSesudah));
    $cek('  nilai ANGSURAN 1 tetap utuh sesudah diulang',
        $sel($jadwal('ANGSURAN 1'))['t'] === '2026-09-21');

    echo "\n=== 8. Tiga slot Angsuran Cash terbentuk urut ===\n";

    // MasterDataKprController mengisi slot 1..3 dari baris ANG urut sequence.
    $ang = $bo->table('payment_schedules')->where('booking_id', $bookingId)
        ->where('payment_type', 'ANG')->orderBy('sequence')->get(['label', 'paid_at', 'paid_amount']);
    $cek('ada tepat tiga baris ANG', $ang->count() === 3, (string) $ang->count());
    $cek('  urutannya ANGSURAN 1, 2, 3',
        $ang->pluck('label')->all() === ['ANGSURAN 1', 'ANGSURAN 2', 'ANGSURAN 3'],
        json_encode($ang->pluck('label')->all()));
    $cek('  Booking Fee TIDAK ikut jadi slot angsuran',
        ! $ang->pluck('label')->contains('BOOKING FEE 1'));
} finally {
    DB::rollBack();
    $bidA = $bo->table('bookings')->where('booking_number', $NO_SPR)->pluck('id')->all();
    if ($bidA) {
        $bo->table('payment_schedules')->whereIn('booking_id', $bidA)->delete();
        $bo->table('bookings')->whereIn('id', $bidA)->delete();
    }
    $bo->table('customers')->where('code', 'CUST-'.$TAG)->delete();
    $bo->table('units')->where('unit_code', 'UNIT-'.$TAG)->delete();
}

// ─────────────────────────────────────────────────────────────────────────────
// BAGIAN B — PEMICUNYA. Di luar transaksi: dispatch memakai afterCommit.
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== 9. Peristiwa yang WAJIB memicu sinkron ulang ===\n";

$B = $TAG.'B';
$bersihkanB = [];
try {
    $custB = (int) DB::table('fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'code' => 'CUST-'.$B,
        'full_name' => 'Pembeli Uji Pemicu', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitB = (int) DB::table('fo_units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode_unit' => 'UNIT-'.$B,
        'cluster' => 'Cluster Uji', 'blok' => 'A', 'no_unit' => '16',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $kontrakB = (int) DB::table('fo_kontrak')->insertGetId([
        'tenant_id' => $tenant, 'no_kontrak' => 'UJI-'.$B, 'customer_id' => $custB, 'unit_id' => $unitB,
        'tgl_kontrak' => '2026-08-01', 'harga_jual' => 100000000, 'total_kontrak' => 100000000,
        'cara_bayar' => 'CASH_BERTAHAP', 'status' => 'AKTIF', 'tahap' => 'SPR',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bersihkanB = ['cust' => $custB, 'unit' => $unitB, 'kontrak' => $kontrakB];

    /**
     * Berapa job syncSpr untuk kontrak ini yang ter-dispatch oleh satu tindakan.
     *
     * Dihitung lewat QueueFake::pushed(), BUKAN Queue::assertPushed(): assert* milik
     * Laravel memanggil PHPUnit\Framework\Assert, dan paket dev itu tidak ikut di image
     * produksi tempat uji ini berjalan.
     */
    $hitung = function (callable $tindakan) use ($kontrakB): int {
        $antrean = Queue::fake();
        $tindakan();

        return $antrean->pushed(SyncFoToIproJob::class)
            ->filter(fn ($job) => $job->method === 'syncSpr' && (int) $job->modelId === $kontrakB)
            ->count();
    };

    $tagihanB = null;
    $n = $hitung(function () use ($kontrakB, &$tagihanB) {
        // Pembuatan baris tagihan terjadi MASSAL saat jadwal digenerate dan sudah diikuti
        // Kontrak::saved — satu job per baris hanya akan menggandakan pekerjaan yang sama.
        $tagihanB = KontrakTagihan::create([
            'kontrak_id' => $kontrakB, 'urutan' => 1, 'tipe' => 'ANG', 'nama_tagihan' => 'ANGSURAN 1',
            'jatuh_tempo' => '2026-09-01', 'nilai' => 100000000, 'pelunasan' => 0,
        ]);
    });
    $cek('membuat baris tagihan TIDAK memicu sinkron', $n === 0, 'ter-dispatch '.$n.'x');

    $n = $hitung(function () use ($tagihanB) {
        $tagihanB->pelunasan = 100000000;
        $tagihanB->save();
    });
    $cek('pelunasan berubah (pembayaran) MEMICU sinkron', $n === 1, 'ter-dispatch '.$n.'x');

    $n = $hitung(function () use ($tagihanB) {
        $tagihanB->jatuh_tempo = '2026-10-01';
        $tagihanB->save();
    });
    $cek('  mengubah kolom lain pada tagihan tidak ikut memicu', $n === 0, 'ter-dispatch '.$n.'x');

    $bkmB = null;
    $n = $hitung(function () use ($kontrakB, $custB, $tenant, $B, $tagihanB, &$bkmB) {
        $bkmB = Bkm::create([
            'tenant_id' => $tenant, 'no_bkm' => 'BKM-'.$B, 'tgl_bkm' => '2026-09-21',
            'customer_id' => $custB, 'kontrak_id' => $kontrakB, 'kontrak_tagihan_id' => $tagihanB->id,
            'amount' => 100000000, 'jenis_pembayaran' => 'ANGSURAN', 'status' => 'POSTED',
        ]);
    });
    $cek('kwitansi terbit MEMICU sinkron', $n === 1, 'ter-dispatch '.$n.'x');

    if ($adaBoPosted) {
        // Tombol "Posting BO" pada daftar Kwitansi HANYA mengubah kolom ini. Tanpa hook-nya,
        // kwitansi yang baru saja memenuhi syarat "Terkirim" tak pernah sampai ke BO.
        $n = $hitung(fn () => $bkmB->forceFill(['bo_posted_at' => now()])->save());
        $cek('stempel "Terkirim" (bo_posted_at) MEMICU sinkron', $n === 1, 'ter-dispatch '.$n.'x');
    }

    $n = $hitung(fn () => $bkmB->update(['keterangan' => 'catatan saja']));
    $cek('  mengubah keterangan kwitansi tidak ikut memicu', $n === 0, 'ter-dispatch '.$n.'x');

    $n = $hitung(fn () => $bkmB->update(['status' => 'BATAL']));
    $cek('pembatalan kwitansi MEMICU sinkron', $n === 1, 'ter-dispatch '.$n.'x');
} finally {
    // Di luar transaksi → dibersihkan sendiri.
    if ($bersihkanB) {
        DB::table('fo_bkm')->where('kontrak_id', $bersihkanB['kontrak'])->delete();
        DB::table('fo_kontrak_tagihan')->where('kontrak_id', $bersihkanB['kontrak'])->delete();
        DB::table('fo_kontrak')->where('id', $bersihkanB['kontrak'])->delete();
        DB::table('fo_units')->where('id', $bersihkanB['unit'])->delete();
        DB::table('fo_customers')->where('id', $bersihkanB['cust'])->delete();
    }
    $bo->table('customers')->where('code', 'CUST-'.$B)->delete();
    $bo->table('units')->where('unit_code', 'UNIT-'.$B)->delete();

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
    echo "(data uji dibatalkan & baris ipro_bo dibersihkan)\n";
}
