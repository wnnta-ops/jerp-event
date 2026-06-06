<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Services\Numerator\NumeratorServiceInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateKontrak extends CreateRecord
{
    protected static string $resource = KontrakResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['no_kontrak'])) {
            $code = match ($data['cara_bayar'] ?? 'KPR') {
                'KPR' => 'KPR',
                default => 'KONTRAK',
            };
            $data['no_kontrak'] = app(NumeratorServiceInterface::class)->next($code);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        DB::transaction(function () {
            if ($unit = Unit::find($this->record->unit_id)) {
                $unit->update(['status' => 'TERJUAL']);
            }
            if ($this->record->reservasi_id && $r = Reservasi::find($this->record->reservasi_id)) {
                $r->update(['status' => 'NAIK_KONTRAK']);
            }
        });
    }
}
