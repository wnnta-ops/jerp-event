<?php

namespace App\Filament\Fo\Resources\ReservasiResource\Pages;

use App\Filament\Fo\Resources\ReservasiResource;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateReservasi extends CreateRecord
{
    protected static string $resource = ReservasiResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_reservasi'])) {
            $data['no_reservasi'] = app(NumeratorServiceInterface::class)->next('RSV');
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Mark unit as BOOKING when reservasi created
        DB::transaction(function () {
            if ($unit = Unit::find($this->record->unit_id)) {
                $unit->update(['status' => 'BOOKING']);
            }
        });
    }
}
