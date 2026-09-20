<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsCmUnit;
use App\Domain\BusinessSupport\Services\KomplainApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Tarik data BAST per unit dari sistem Komplain ke salinan lokal bs_cm_units.
 *
 * Serah terima unit dicatat di sana, bukan di BO — itulah sebabnya layar Detail BAST
 * menampilkan "Belum BAST" untuk rumah yang sebenarnya sudah diserahkan.
 *
 * Bertahap secara bawaan lewat updated_since, memakai stempel dari DATA (diperbarui
 * terbesar yang sudah tersimpan) — bukan jam server kita, karena selisih jam antar-server
 * bisa membuat perubahan terlewat.
 *
 * Aman diulang: penyelarasan lewat updateOrCreate pada cm_id.
 */
class TarikUnitKomplainCommand extends Command
{
    protected $signature = 'cm:tarik-unit
        {--penuh : Tarik semua, abaikan stempel tarikan terakhir.}';

    protected $description = 'Tarik data BAST per unit dari sistem Komplain (bs_cm_units)';

    public function handle(KomplainApi $api): int
    {
        if (! $api->aktif()) {
            $this->error('  Integrasi sistem Komplain belum aktif — isi CM_API_KEY di .env, lalu ulangi.');

            return self::FAILURE;
        }

        $sejak = $this->option('penuh') ? null : BsCmUnit::max('diperbarui');
        $sejak = $sejak ? Carbon::parse($sejak)->toIso8601String() : null;

        $this->line($sejak ? '  Menarik perubahan sejak '.$sejak : '  Menarik SELURUH unit.');

        try {
            $baris = $api->unit($sejak);
        } catch (RuntimeException $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $baru = 0;
        $ubah = 0;
        $sekarang = now();

        foreach ($baris as $u) {
            $cmId = (int) ($u['id'] ?? 0);
            $kode = trim((string) ($u['code'] ?? ''));
            if ($cmId <= 0) {
                continue;
            }

            // Sengaja TIDAK menyalin access_code, owner_name/phone/email, dan URL tanda
            // tangan: access_code adalah kredensial login APP Warga, sisanya PII yang tidak
            // dipakai layar Detail BAST. Menyalin data pribadi yang tak terpakai hanya
            // menambah tempat ia bisa bocor.
            $model = BsCmUnit::updateOrCreate(['cm_id' => $cmId], [
                'tenant_id' => tenant_id(),
                'kode_unit' => $kode !== '' ? $kode : null,
                'kode_upper' => $kode !== '' ? mb_strtoupper($kode) : null,
                'cluster_kode' => $u['cluster_code'] ?? null,
                'cluster_nama' => $u['cluster_name'] ?? null,
                'nama' => $u['name'] ?? null,
                'bast_date' => $u['bast_date'] ?? null,
                'bast_no' => $u['bast_no'] ?? null,
                'bast_signed_at' => $u['bast_signed_at'] ?? null,
                'bast_handover_name' => $u['bast_handover_name'] ?? null,
                'is_active' => (bool) ($u['is_active'] ?? true),
                'diperbarui' => $u['updated_at'] ?? null,
                'ditarik_pada' => $sekarang,
            ]);

            $model->wasRecentlyCreated ? $baru++ : $ubah++;
        }

        $adaTgl = BsCmUnit::whereNotNull('bast_date')->count();
        $adaNo = BsCmUnit::whereNotNull('bast_no')->where('bast_no', '<>', '')->count();
        $total = BsCmUnit::count();

        $this->newLine();
        $this->table(['Keadaan', 'Unit'], [
            ['Diterima', count($baris)],
            ['Baru', $baru],
            ['Diperbarui', $ubah],
            ['Total tersimpan', $total],
            ['Punya tanggal BAST', $adaTgl],
            ['Punya no. dokumen BAST', $adaNo],
        ]);

        if ($total > 0 && $adaNo === 0) {
            $this->newLine();
            $this->warn('  Tidak satu pun unit punya no. dokumen BAST di sistem Komplain.');
            $this->warn('  Kolom No. Dokumen di layar tetap diisi dokumen BAST milik BO sendiri.');
        }

        return self::SUCCESS;
    }
}
