<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKontraks extends ListRecords
{
    protected static string $resource = KontrakResource::class;

    protected function getHeaderActions(): array
    {
        // Tanpa tombol "Buat SPRS": SPRS TIDAK dibuat manual, melainkan terbit dari
        // alur Pilih Unit / Verifikasi Booked. Membuatnya manual melewatkan reservasi,
        // jadwal tagihan, dan jejak NUP-nya.
        return [];
    }
}
