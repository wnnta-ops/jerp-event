<?php

namespace App\Filament\Fo\Resources\TemplateDokumenResource\Pages;

use App\Filament\Fo\Resources\TemplateDokumenResource;
use App\Modules\FO\Komunikasi\Models\TemplateDokumen;
use App\Modules\FO\Komunikasi\Support\DokumenBawaan;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTemplateDokumen extends CreateRecord
{
    protected static string $resource = TemplateDokumenResource::class;

    protected function fillForm(): void
    {
        // ?dari=<id> → salin isi versi itu ("Versi baru dari ini"); selain itu mulai dari SPR
        // dengan isi versi yang sedang berlaku / teks bawaan.
        $dari = TemplateDokumen::find(request()->integer('dari'));
        $dokumen = $dari?->dokumen ?? DokumenBawaan::SPR;
        $lokasiId = $dari?->lokasi_id;

        $this->form->fill([
            'dokumen' => $dokumen,
            'lokasi_id' => $lokasiId,
            'berlaku_mulai' => today()->addDay()->toDateString(),
            'isi' => $dari
                ? array_replace(DokumenBawaan::untuk($dokumen), (array) $dari->isi)
                : TemplateDokumenResource::isiAwal($dokumen, $lokasiId),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['versi'] = TemplateDokumen::versiBerikutnya($data['dokumen'], $data['lokasi_id'] ?? null);
        $data['created_by'] = Auth::id();

        return $data;
    }
}
