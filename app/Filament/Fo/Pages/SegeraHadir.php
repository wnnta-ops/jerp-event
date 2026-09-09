<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use Filament\Pages\Page;

/**
 * Placeholder generik untuk item menu yang STRUKTURNYA sudah dipasang tapi
 * LOGIKA/flow-nya belum didefinisikan (menunggu detail dari user). Satu kelas
 * dipakai banyak item menu via query string ?modul=...&fitur=...
 *
 * Contoh: /fo/segera-hadir?modul=Komisi&fitur=Setup+Tarif
 */
class SegeraHadir extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $slug = 'segera-hadir';

    protected static string $view = 'filament.fo.pages.segera-hadir';

    // Tidak tampil sebagai item navigasi sendiri — diakses lewat link menu.
    protected static bool $shouldRegisterNavigation = false;

    public string $modul = '';

    public string $fitur = '';

    public function mount(): void
    {
        $this->modul = (string) request()->query('modul', '');
        $this->fitur = (string) request()->query('fitur', '');
    }

    public function getTitle(): string
    {
        return $this->fitur !== '' ? $this->fitur : 'Segera Hadir';
    }
}
