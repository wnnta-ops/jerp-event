<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Biaya administrasi pembatalan = persen × ANGSURAN (tipe ANG) yang sudah dibayar.
        // Bukan dari harga unit: yang dipotong uang yang sudah masuk, bukan nilai kontraknya.
        //
        // Ini persen BAWAAN. Persen per Cluster+Tahap ada di kolom fo_tahap.persen_batal_admin
        // — fo_tahap memang sudah satu baris per kombinasi cluster+tahap (punya lokasi_id),
        // jadi menyimpannya di sana lebih tepat daripada menumpuk daftar di setelan.
        $this->migrator->add('collection.batal_admin_persen', 40.0);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('collection.batal_admin_persen');
    }
};
