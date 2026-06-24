<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Setup Siteplan Live (mode acara NUP) — di Pengaturan FO.
 *  - show_stock        : tampilkan kotak Stock Status (Ready/Sold).
 *  - sold_animation    : animasi SOLD OUT otomatis saat scope 100% terjual.
 *  - focus_lokasi_id   : cluster (Lokasi) yang dipantau (null = seluruh project).
 *  - focus_tahap       : tahap yang dipantau (kosong = semua tahap).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('fo.siteplanlive_show_stock', true);
        $this->migrator->add('fo.siteplanlive_sold_animation', true);
        $this->migrator->add('fo.siteplanlive_focus_lokasi_id', null);
        $this->migrator->add('fo.siteplanlive_focus_tahap', '');
    }
};
