<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Services\MukApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Tarik kontrak owner dari MUK ERP ke tabel salinan bs_muk_contracts.
 *
 * Bertahap secara bawaan: hanya kontrak yang berubah sejak tarikan terakhir
 * (?diperbarui_sejak=), sesuai anjuran dokumen MUK. Tanpa itu setiap penarikan menyeret
 * seluruh 101 kontrak dan memakan jatah 120 permintaan/menit tanpa guna.
 *
 * Aman diulang: penyelarasan lewat updateOrCreate pada muk_id, jadi menjalankannya dua kali
 * tidak menggandakan apa pun.
 */
class TarikKontrakMukCommand extends Command
{
    protected $signature = 'muk:tarik-kontrak
        {--penuh : Tarik semua, abaikan stempel tarikan terakhir.}';

    protected $description = 'Tarik kontrak owner dari MUK ERP ke salinan lokal (bs_muk_contracts)';

    public function handle(MukApi $muk): int
    {
        if (! $muk->aktif()) {
            $this->error('  Integrasi MUK belum aktif — isi MUK_API_KEY di .env, lalu ulangi.');

            return self::FAILURE;
        }

        // Stempel diambil dari data, bukan dari waktu jalannya perintah: kalau perintah
        // sebelumnya gagal di tengah, yang belum masuk tetap ikut tertarik kali ini.
        $sejak = $this->option('penuh') ? null : BsMukContract::max('diperbarui');
        $sejak = $sejak ? Carbon::parse($sejak)->toIso8601String() : null;

        $this->line($sejak ? '  Menarik perubahan sejak '.$sejak : '  Menarik SELURUH kontrak.');

        try {
            $baris = $muk->kontrak($sejak);
        } catch (RuntimeException $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $baru = 0;
        $ubah = 0;
        $sekarang = now();

        foreach ($baris as $k) {
            $mukId = (int) ($k['id'] ?? 0);
            if ($mukId <= 0) {
                continue;
            }

            $ku = (array) ($k['kumulatif_hari_ini'] ?? []);
            $model = BsMukContract::updateOrCreate(['muk_id' => $mukId], [
                'tenant_id' => tenant_id(),
                'no_kontrak' => $k['no_kontrak'] ?? null,
                'no_spk' => $k['no_spk'] ?? null,
                'jenis' => $k['jenis'] ?? null,
                'status' => $k['status'] ?? null,
                'proyek_kode' => data_get($k, 'proyek.kode'),
                'proyek_nama' => data_get($k, 'proyek.nama'),
                'pelanggan_kode' => data_get($k, 'pelanggan.kode'),
                'pelanggan_nama' => data_get($k, 'pelanggan.nama'),
                'tanggal' => $k['tanggal'] ?? null,
                'mulai' => $k['mulai'] ?? null,
                'selesai' => $k['selesai'] ?? null,
                'nilai_kontrak' => (float) ($k['nilai_kontrak'] ?? 0),
                'retensi_persen' => (float) ($k['retensi_persen'] ?? 0),
                'kum_diopname' => (float) ($ku['diopname'] ?? 0),
                'kum_ditagih_neto' => (float) ($ku['ditagih_neto'] ?? 0),
                'kum_diterima' => (float) ($ku['diterima'] ?? 0),
                'kum_retensi_ditahan' => (float) ($ku['retensi_ditahan'] ?? 0),
                'diperbarui' => $k['diperbarui'] ?? null,
                'ditarik_pada' => $sekarang,
            ]);

            $model->wasRecentlyCreated ? $baru++ : $ubah++;
        }

        $this->newLine();
        $this->table(['Keadaan', 'Kontrak'], [
            ['Diterima dari MUK', count($baris)],
            ['Baru', $baru],
            ['Diperbarui', $ubah],
            ['Total tersimpan', BsMukContract::count()],
        ]);

        $janggal = BsMukContract::whereColumn('kum_ditagih_neto', '>', 'kum_diopname')
            ->orWhereColumn('kum_diopname', '>', 'nilai_kontrak')->count();

        if ($janggal > 0) {
            $this->newLine();
            $this->warn("  {$janggal} kontrak angkanya janggal menurut MUK sendiri — ditagih melebihi opname,");
            $this->warn('  atau opname melebihi nilai kontrak. Ditandai di layar; perlu ditanyakan ke tim MUK.');
        }

        return self::SUCCESS;
    }
}
