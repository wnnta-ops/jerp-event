<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Kode akses supaya tim tetap bisa menguji formulir saat pemeliharaan
        // menyala. Login panel tidak bisa dipakai sebagai penanda: formulir NUP
        // berada di subdomain berbeda dari panel, sehingga sesinya tidak terbawa.
        $this->migrator->add('fo.nup_public_maintenance_bypass', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('fo.nup_public_maintenance_bypass');
    }
};
