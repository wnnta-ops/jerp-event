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

    /** Satu submit = banyak nama barang (+ harga) → satu baris Gimmick per item. */
    protected function handleRecordCreation(array $data): Model
    {
        $items = collect($data['nama_barang_list'] ?? [])
            ->map(fn ($n) => is_array($n) ? $n : ['nama' => (string) $n, 'harga' => 0])
            ->filter(fn ($n) => trim((string) ($n['nama'] ?? '')) !== '')
            ->values();

        unset($data['nama_barang_list']);

        // Fallback: kalau tak ada list (mis. edit pakai 'name'), buat satu.
        if ($items->isEmpty()) {
            return Gimmick::create($data);
        }

        $first = null;
        foreach ($items as $it) {
            $row = Gimmick::create(array_merge($data, [
                'name' => trim((string) $it['nama']),
                'nilai' => \App\Support\Rupiah::toFloat($it['harga'] ?? 0),
            ]));
            $first ??= $row;
        }

        if ($items->count() > 1) {
            Notification::make()->success()
                ->title($items->count().' item gimmick dibuat')
                ->send();
        }

        return $first;
    }
}
