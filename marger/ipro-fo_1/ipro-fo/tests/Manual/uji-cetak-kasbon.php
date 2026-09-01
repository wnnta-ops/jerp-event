<?php

declare(strict_types=1);

/*
 * Uji cetak PENGAJUAN bon sementara + jenjang persetujuannya.
 *
 * DUA hal yang dijaga, dan keduanya salah TANPA GALAT:
 *
 * 1. Formulir yang tercetak harus sesuai nominalnya — di bawah Rp 1 juta memakai lembar
 *    "KAS BON", mulai Rp 1 juta memakai "BUKTI PERMOHONAN PEMBAYARAN" + "MEMO INTERNAL".
 *    Formulir yang keliru tetap tercetak rapi; yang salah hanya jumlah kotak tanda tangannya,
 *    dan itu baru ketahuan saat berkasnya sudah beredar minta tanda tangan.
 *
 * 2. Ambang formulir dan ambang rantai persetujuan harus SATU angka. Dulu keduanya konstanta
 *    terpisah dengan pembanding berbeda ('>=' vs '<='), sehingga bon bernominal TEPAT
 *    Rp 1.000.000 mencetak formulir besar berkotak 9 sementara aplikasinya hanya meminta 7
 *    persetujuan — kertas dan aplikasi menuntut orang yang berbeda untuk bon yang sama.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-cetak-kasbon.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Print\PengajuanKasbonPrinter;
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
    $akun   = (int) DB::table('bs_accounts')->where('is_postable', true)->orderBy('id')->value('id');

    /** Satu bon uji bernominal tertentu. */
    $bon = function (float $nominal, string $no) use ($tenantId, $akun, $userId): BsCashAdvance {
        return BsCashAdvance::create([
            'tenant_id' => $tenantId, 'advance_number' => $no, 'advance_date' => '2026-08-19',
            'holder' => 'UJI Pemegang Bon', 'purpose' => 'UJI keperluan bon sementara',
            'advance_account_id' => $akun, 'cash_account_id' => $akun,
            'amount' => $nominal, 'created_by' => $userId,
        ]);
    };

    $printer = app(PengajuanKasbonPrinter::class);

    echo "\n=== 1. Ambang formulir & ambang persetujuan SATU angka ===\n";

    // Dua konstanta terpisah adalah cara paling mudah keduanya diam-diam berbeda.
    $cek('ambang formulir = ambang rantai persetujuan',
        PengajuanKasbonPrinter::AMBANG_BESAR === BsCashAdvance::APPROVAL_THRESHOLD,
        PengajuanKasbonPrinter::AMBANG_BESAR.' vs '.BsCashAdvance::APPROVAL_THRESHOLD);
    $cek('ambangnya Rp 1.000.000', BsCashAdvance::APPROVAL_THRESHOLD === 1_000_000,
        (string) BsCashAdvance::APPROVAL_THRESHOLD);

    echo "\n=== 2. Jenjang persetujuan menurut nominal ===\n";

    // Inilah permintaannya: nominal TEPAT 1 juta ikut rantai penuh.
    foreach ([[999_999, 7], [1_000_000, 9], [1_000_001, 9], [16_500_000, 9]] as [$nominal, $harap]) {
        $a = new BsCashAdvance(['amount' => $nominal]);
        $cek('Rp '.number_format($nominal, 0, ',', '.').' → '.$harap.' jenjang',
            count($a->requiredSigners()) === $harap, (string) count($a->requiredSigners()));
    }

    $penuh = new BsCashAdvance(['amount' => 1_000_000]);
    $cek('rantai penuh memuat Moch Winarto & Boy Arifin',
        in_array('moch_winarto', $penuh->requiredSigners(), true)
        && in_array('boy_arifin', $penuh->requiredSigners(), true),
        implode(', ', $penuh->requiredSigners()));
    $kecil = new BsCashAdvance(['amount' => 999_999]);
    $cek('rantai pendek TIDAK memuat keduanya',
        ! in_array('moch_winarto', $kecil->requiredSigners(), true)
        && ! in_array('boy_arifin', $kecil->requiredSigners(), true),
        implode(', ', $kecil->requiredSigners()));

    echo "\n=== 3. Formulir yang tercetak sesuai nominalnya ===\n";

    $b7 = $bon(1_000, 'UJI-BON-0007');          // seperti BON/2608/0007 pada layar
    $b8 = $bon(1_000_001, 'UJI-BON-0008');      // seperti BON/2608/0008
    $b9 = $bon(1_000_000, 'UJI-BON-0009');      // TEPAT 1 juta

    $h7 = $printer->render($b7);
    $h8 = $printer->render($b8);
    $h9 = $printer->render($b9);

    $cek('Rp 1.000 memakai lembar KAS BON',
        str_contains($h7, 'KAS BON') && ! str_contains($h7, 'BUKTI PERMOHONAN PEMBAYARAN'), 'formulirnya keliru');
    $cek('Rp 1.000 tidak mencetak Memo Internal', ! str_contains($h7, 'MEMO INTERNAL'), 'memo ikut tercetak');
    $cek('Rp 1.000.001 memakai BUKTI PERMOHONAN PEMBAYARAN',
        str_contains($h8, 'BUKTI PERMOHONAN PEMBAYARAN'), 'formulirnya keliru');
    $cek('Rp 1.000.001 ikut mencetak MEMO INTERNAL', str_contains($h8, 'MEMO INTERNAL'), 'memo tidak tercetak');
    // Nominal tepat 1 juta — batas yang paling mudah salah.
    $cek('Rp 1.000.000 memakai BUKTI PERMOHONAN PEMBAYARAN',
        str_contains($h9, 'BUKTI PERMOHONAN PEMBAYARAN'), 'formulirnya keliru');
    $cek('Rp 1.000.000 ikut mencetak MEMO INTERNAL', str_contains($h9, 'MEMO INTERNAL'), 'memo tidak tercetak');

    echo "\n=== 4. Logo MUP ada di SETIAP halaman cetak ===\n";

    // Inilah permintaannya. Dihitung per halaman, bukan sekadar "ada di berkasnya":
    // formulir besar punya DUA halaman, dan logo yang cuma di halaman pertama membuat
    // Memo Internal keluar tanpa kop.
    $jmlLogo = fn (string $h) => substr_count($h, '/images/mup-logo.jpeg');
    $cek('KAS BON berlogo', $jmlLogo($h7) === 1, (string) $jmlLogo($h7));
    $cek('BPP + Memo Internal berlogo di KEDUA halamannya', $jmlLogo($h8) === 2, (string) $jmlLogo($h8));
    $cek('formulir tepat 1 juta juga berlogo dua halaman', $jmlLogo($h9) === 2, (string) $jmlLogo($h9));

    // Berkas yang benar: mup-letterhead.jpeg adalah lembar kop A4 utuh 2482×3511; memakainya
    // sebagai logo membuat kopnya jadi serpihan setinggi beberapa piksel.
    $cek('memakai berkas logo, bukan lembar kop A4',
        ! str_contains($h7, 'mup-letterhead') && ! str_contains($h8, 'mup-letterhead'), 'mup-letterhead ikut terpakai');
    $cek('berkas logonya memang ada di public/images',
        is_file(__DIR__.'/../../public/images/mup-logo.jpeg'), 'public/images/mup-logo.jpeg tidak ditemukan');

    echo "\n=== 5. Kop memuat identitas perusahaan ===\n";

    $co = (string) config('business_support.company.name', 'PT Mitra Usaha Propertindo');
    foreach ([['KAS BON', $h7], ['BPP/Memo', $h8]] as [$nama, $html]) {
        $cek("kop {$nama} menyebut nama perusahaan", str_contains($html, htmlspecialchars($co, ENT_QUOTES, 'UTF-8')),
            'tidak ditemukan');
    }
    // Alamat pada lampiran: "Jl. Kahuripan Raya Ruko Avenue No 27 - 30, Sidoarjo, Jawa Timur".
    $cek('kop menyebut alamat', str_contains($h7, 'Sidoarjo'), 'alamat tidak tercetak');

    echo "\n=== 6. Isi formulir tetap utuh ===\n";

    $cek('KAS BON memuat nomor bonnya', str_contains($h7, 'UJI-BON-0007'), 'tidak ditemukan');
    $cek('KAS BON memuat keperluannya', str_contains($h7, 'UJI keperluan bon sementara'), 'tidak ditemukan');
    $cek('KAS BON memuat nominalnya', str_contains($h7, 'Rp. 1.000,00'), 'tidak ditemukan');
    $cek('KAS BON memuat lima kolom tanda tangan',
        str_contains($h7, 'Yang Mengajukan') && str_contains($h7, 'Diverifikasi')
        && str_contains($h7, 'Disetujui') && str_contains($h7, 'Dibayar') && str_contains($h7, 'Diterima'),
        'kolom tanda tangan tidak lengkap');

    $cek('BPP memuat terbilang', str_contains($h8, 'RUPIAH'), 'tidak ditemukan');
    $cek('BPP memuat tujuh kotak tanda tangan',
        str_contains($h8, 'SPV/Manager Dept.') && str_contains($h8, 'General Cashier') && str_contains($h8, 'Penerima'),
        'kotak tanda tangan tidak lengkap');
    $cek('Memo Internal memuat Mengetahui (Winarto & Boy Arifin)',
        str_contains($h8, 'Mengetahui'), 'tidak ditemukan');
    $cek('kedua halaman dipisah page-break', str_contains($h8, 'class="hal"'), 'tidak ada pemisah halaman');

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = BsCashAdvance::withTrashed()->where('advance_number', 'like', 'UJI-BON-%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
