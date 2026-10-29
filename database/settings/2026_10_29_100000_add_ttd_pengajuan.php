<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    private const PROPERTI = [
        'fo.ttd_blt_diajukan',
        'fo.ttd_blt_diperiksa',
        'fo.ttd_blt_diketahui',
        'fo.ttd_blt_disetujui',
        'fo.ttd_komisi_diajukan',
        'fo.ttd_komisi_diperiksa',
        'fo.ttd_komisi_diketahui',
        'fo.ttd_komisi_disetujui',
    ];

    public function up(): void
    {
        // Nama penanda tangan cetak Pengajuan BLT & Komisi. Bawaan null = ruang kosong
        // untuk ditulis tangan, sama dengan cetakan sebelum setelan ini ada.
        // Berpenjaga exists(): add() melempar bila propertinya sudah ada.
        foreach (self::PROPERTI as $p) {
            if (! $this->migrator->exists($p)) {
                $this->migrator->add($p, null);
            }
        }
    }

    public function down(): void
    {
        foreach (self::PROPERTI as $p) {
            $this->migrator->deleteIfExists($p);
        }
    }
};
