<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Penyederhanaan (permintaan): saklar auto_kirim_wa dihapus — pengiriman
        // otomatis kini dikontrol toggle channel Email/WA saja. Keduanya di-RESET
        // MATI (insiden email massal) — nyalakan sadar lewat Setup Termin SP.
        $this->migrator->deleteIfExists('collection.auto_kirim_wa');
        $this->migrator->update('collection.channel_email', fn () => false);
        $this->migrator->update('collection.channel_wa', fn () => false);
    }

    public function down(): void
    {
        // Tidak ada pemulihan otomatis — nilai channel diatur manual via Setup.
    }
};
