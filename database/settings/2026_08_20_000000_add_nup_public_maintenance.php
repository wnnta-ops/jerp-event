<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Sakelar pemeliharaan formulir NUP publik. Dipisah dari tanggal buka/tutup
        // acara (NupEvent::openNow()): menutup acara mengubah arti data, sedangkan
        // ini hanya menutup pintu masuk sementara tanpa menyentuh acaranya.
        $this->migrator->add('fo.nup_public_maintenance', false);
        $this->migrator->add('fo.nup_public_maintenance_start', null);
        $this->migrator->add('fo.nup_public_maintenance_end', null);
        $this->migrator->add('fo.nup_public_maintenance_note', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('fo.nup_public_maintenance');
        $this->migrator->deleteIfExists('fo.nup_public_maintenance_start');
        $this->migrator->deleteIfExists('fo.nup_public_maintenance_end');
        $this->migrator->deleteIfExists('fo.nup_public_maintenance_note');
    }
};
