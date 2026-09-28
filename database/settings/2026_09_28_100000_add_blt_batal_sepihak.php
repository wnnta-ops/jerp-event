<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // BLT yang diterima sales ketika kontrak dibatalkan sepihak oleh pembeli. Nilainya
        // TETAP, bukan turunan nilai kontrak — justru karena kontraknya tidak jadi, tidak ada
        // nilai kontrak yang pantas dijadikan dasar.
        // Berpenjaga exists(): add() melempar bila propertinya sudah ada, dan itu akan
        // menghentikan SELURUH migrasi hanya karena satu baris setelan terlanjur dibuat.
        $this->tambah('fo.blt_batal_sepihak_nilai', 1_500_000.0);

        // PPh 21 atas BLT di atas. Sama dengan tarif koordinator di BltCalculatorService,
        // tetapi disimpan sendiri supaya bisa diubah tanpa menyentuh perhitungan skema.
        $this->tambah('fo.blt_batal_sepihak_pph_persen', 2.5);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('fo.blt_batal_sepihak_nilai');
        $this->migrator->deleteIfExists('fo.blt_batal_sepihak_pph_persen');
    }

    private function tambah(string $properti, float $nilai): void
    {
        if (! $this->migrator->exists($properti)) {
            $this->migrator->add($properti, $nilai);
        }
    }
};
