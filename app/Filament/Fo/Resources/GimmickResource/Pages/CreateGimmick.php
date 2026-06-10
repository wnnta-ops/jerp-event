<?php

namespace App\Filament\Fo\Resources\GimmickResource\Pages;

use App\Filament\Fo\Resources\GimmickResource;
use App\Modules\FO\Adminjual\Models\Gimmick;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGimmick extends CreateRecord
{
    protected static string $resource = GimmickResource::class;

    /** Satu submit = banyak nama barang → satu baris Gimmick per nama. */
    protected function handleRecordCreation(array $data): Model
    {
        $names = collect($data['nama_barang_list'] ?? [])
            ->map(fn ($n) => is_array($n) ? ($n['nama'] ?? '') : $n)
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->values();

        unset($data['nama_barang_list']);

        // Fallback: kalau tak ada list (mis. edit pakai 'name'), buat satu.
        if ($names->isEmpty()) {
            return Gimmick::create($data);
        }

        $first = null;
        foreach ($names as $nama) {
            $row = Gimmick::create(array_merge($data, ['name' => $nama]));
            $first ??= $row;
        }

        if ($names->count() > 1) {
            Notification::make()->success()
                ->title($names->count().' item gimmick dibuat')
                ->send();
        }

        return $first;
    }
}
