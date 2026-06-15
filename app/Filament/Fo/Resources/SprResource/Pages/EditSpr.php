<?php

namespace App\Filament\Fo\Resources\SprResource\Pages;

use App\Filament\Fo\Resources\KontrakResource\Pages\EditKontrak;
use App\Filament\Fo\Resources\SprResource;
use Filament\Actions;

/**
 * Edit SPR — sama dengan edit SPRS (tab + Bentuk Ulang dari Master), tapi
 * tanpa tombol "Simpan sebagai SPR" (sudah SPR).
 */
class EditSpr extends EditKontrak
{
    protected static string $resource = SprResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->regenAction(),
            $this->freeDpAction(),
            $this->cancelFreeDpAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
