<?php

namespace App\Filament\Fo\Resources\SprResource\Pages;

use App\Filament\Fo\Resources\KontrakResource\Pages\EditKontrak;
use App\Filament\Fo\Resources\SprResource;
use Filament\Actions;

/**
 * Edit SPR — sama dengan edit SPRS (tab), tapi tanpa tombol "Simpan sebagai SPR"
 * (sudah SPR).
 */
class EditSpr extends EditKontrak
{
    protected static string $resource = SprResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->cetakAction(),
            $this->freeDpAction(),
            $this->cancelFreeDpAction(),
            $this->cancelSubsidiAction(),
            $this->tambahDpAction(),
            $this->cancelTambahDpAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
