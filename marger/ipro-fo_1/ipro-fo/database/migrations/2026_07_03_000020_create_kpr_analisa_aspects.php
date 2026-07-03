<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Analisa Berkas — konfigurasi scoring tahap "Analisa BI Checking & Berkas" (Inisiasi KPR).
 * Tiap aspek menyimpan 3 opsi (high/medium/low) di kolom JSON: label, hint, skor indikator
 * (default 3/2/1, dapat diubah), dan skor rekomendasi bank [BTN,BSN,BRI,BNI,MANDIRI].
 * Di-seed persis dari nilai hardcoded yang berlaku sekarang agar perilaku tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpr_analisa_aspects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('aspect_key', 64);
            $t->unsignedInteger('aspect_no')->default(0);
            $t->string('label', 255);
            $t->string('scope', 16)->default('all'); // all | karyawan | wirausaha
            $t->boolean('is_active')->default(true);
            $t->json('options'); // [{level,desc,hint,score,bank:[5]}]
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'aspect_key']);
            $t->index(['tenant_id', 'scope']);
        });

        $tenantId = DB::table('tenants')->orderBy('id')->value('id');
        if (! $tenantId) {
            return;
        }

        $opt = fn (string $level, string $desc, ?string $hint, int $score, array $bank) => compact('level', 'desc', 'hint', 'score', 'bank');

        $aspects = [
            ['bi_pemohon', 1, 'Hasil BI Checking KTP & NPWP Pemohon', 'all', [
                $opt('high', 'Kol 1', null, 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Kol 2', null, 2, [2, 2, 0, 0, 0]),
                $opt('low', 'Kol 3–5', null, 1, [0, 3, 0, 0, 0]),
            ]],
            ['bi_pasangan', 2, 'Hasil BI Checking KTP & NPWP Pasangan', 'all', [
                $opt('high', 'Kol 1', null, 3, [1, 1, 0, 1, 1]),
                $opt('medium', 'Kol 2', null, 2, [2, 2, 0, 0, 0]),
                $opt('low', 'Kol 3–5', null, 1, [0, 3, 0, 0, 0]),
            ]],
            ['pref_customer', 3, 'Karakteristik Customer', 'all', [
                $opt('high', 'Customer kooperatif, dokumen lengkap, respon cepat', null, 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Masih mencari perbandingan bank, kelengkapan dokumen masih kurang', null, 2, [2, 2, 2, 2, 0]),
                $opt('low', 'Tidak kooperatif, respon lambat, menunda proses, belum menentukan cara bayar', null, 1, [0, 0, 0, 0, 0]),
            ]],
            ['pref_developer', 4, 'Preferensi Developer', 'all', [
                $opt('high', 'Platinum', null, 3, [3, 3, 0, 0, 0]),
                $opt('medium', 'Gold / Tier 3', null, 2, [0, 0, 2, 2, 2]),
                $opt('low', 'Bronze', null, 1, [0, 0, 0, 0, 0]),
            ]],
            ['angsuran', 5, 'Pertimbangan Angsuran per Bulan', 'all', [
                $opt('high', 'Rp 3.000.000', 'Angsuran ringan', 3, [1, 1, 1, 0, 0]),
                $opt('medium', 'Rp 4.000.000', 'Angsuran sedang', 2, [2, 2, 1, 1, 1]),
                $opt('low', 'Rp 5.000.000', 'Angsuran berat', 1, [0, 0, 0, 0, 0]),
            ]],
            ['status_kerja', 6, 'Status Karyawan / Pekerjaan', 'karyawan', [
                $opt('high', 'Karyawan tetap, masa kerja > 3 tahun di perusahaan Tbk', null, 3, [3, 3, 3, 3, 3]),
                $opt('medium', 'Karyawan tetap masa kerja < 3 tahun, P3K', null, 2, [2, 2, 2, 0, 0]),
                $opt('low', 'Karyawan kontrak/outsourcing, masa kerja < 1 tahun', null, 1, [1, 1, 0, 0, 0]),
            ]],
            ['slip_gaji', 7, 'Slip Gaji & Rekening Koran 3 Bulan (RPC)', 'karyawan', [
                $opt('high', 'Rp 10.000.000', 'Penghasilan tinggi', 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Rp 7.000.000', 'Penghasilan menengah', 2, [1, 2, 1, 0, 0]),
                $opt('low', 'Rp 5.000.000', 'Penghasilan minimum', 1, [0, 0, 0, 0, 0]),
            ]],
            ['tt_gaji_tunai', 8, 'Tanda Terima Gaji Tunai 3 Bulan (RPC Tambahan)', 'karyawan', [
                $opt('high', 'Rp 5.000.000', 'Penghasilan tinggi', 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Rp 3.500.000', 'Penghasilan menengah', 2, [1, 1, 1, 1, 1]),
                $opt('low', 'Rp 2.500.000', 'Penghasilan minimum', 1, [0, 0, 0, 0, 0]),
            ]],
            ['lap_keuangan', 9, 'Laporan Keuangan & Rekening Koran 6 Bulan (RPC)', 'wirausaha', [
                $opt('high', 'Rp 50.000.000', 'Omzet tinggi', 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Rp 35.000.000', 'Omzet menengah', 2, [2, 2, 0, 0, 0]),
                $opt('low', 'Rp 25.000.000', 'Omzet minimum', 1, [0, 0, 0, 0, 0]),
            ]],
            ['foto_usaha', 10, 'Foto, Denah Lokasi & Shareloc Tempat Usaha', 'wirausaha', [
                $opt('high', 'Foto usaha lengkap & jelas, lokasi jelas, shareloc valid, usaha mudah diverifikasi & aktif', null, 3, [1, 1, 1, 1, 1]),
                $opt('medium', 'Foto usaha lengkap, lokasi ditemukan namun ada ketidaksesuaian (pemilik, lama usaha, dll)', null, 2, [2, 2, 2, 1, 1]),
                $opt('low', 'Foto usaha tidak lengkap, lokasi & shareloc tidak valid, usaha tidak dapat diverifikasi', null, 1, [0, 0, 0, 0, 0]),
            ]],
        ];

        $now = now();
        foreach ($aspects as [$key, $no, $label, $scope, $options]) {
            DB::table('kpr_analisa_aspects')->insert([
                'tenant_id'  => $tenantId,
                'aspect_key' => $key,
                'aspect_no'  => $no,
                'label'      => $label,
                'scope'      => $scope,
                'is_active'  => true,
                'options'    => json_encode($options, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_analisa_aspects');
    }
};
