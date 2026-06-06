<?php

namespace App\Filament\Fo\Resources\RewardResource\Pages;

use App\Filament\Fo\Resources\RewardResource;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;

class CreateReward extends CreateRecord
{
    protected static string $resource = RewardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_reward'])) {
            $data['no_reward'] = app(NumeratorServiceInterface::class)->next('REWARD');
        }

        return $data;
    }
}
