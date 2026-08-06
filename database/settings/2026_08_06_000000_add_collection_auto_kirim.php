<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Default MATI — pengiriman otomatis (WA) dinyalakan sadar lewat Setup Termin SP.
        $this->migrator->add('collection.auto_kirim_wa', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('collection.auto_kirim_wa');
    }
};
