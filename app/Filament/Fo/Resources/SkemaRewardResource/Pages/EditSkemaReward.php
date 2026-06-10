<?php

namespace App\Filament\Fo\Resources\SkemaRewardResource\Pages;

use App\Filament\Fo\Resources\SkemaRewardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSkemaReward extends EditRecord
{
    protected static string $resource = SkemaRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
