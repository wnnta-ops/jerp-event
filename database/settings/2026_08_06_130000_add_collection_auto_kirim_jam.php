<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Jam pengiriman otomatis harian Collection — diubah dari Setup Termin SP.
        $this->migrator->add('collection.auto_kirim_jam', '08:00');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('collection.auto_kirim_jam');
    }
};
