<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditKontrak extends EditRecord
{
    protected static string $resource = KontrakResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->regenAction(),

            // Finalisasi: simpan + promosikan SPRS → SPR (beda dari Simpan biasa).
            Actions\Action::make('saveAsSpr')->label('Simpan sebagai SPR')
                ->icon('heroicon-o-document-check')->color('success')
                ->visible(fn () => $this->record->tahap !== 'SPR')
                ->requiresConfirmation()
                ->modalDescription('Finalkan SPRS ini menjadi SPR. Setelah ini kontrak pindah ke menu SPR.')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->update(['tahap' => 'SPR']);
                    Notification::make()->success()
                        ->title('Disimpan sebagai SPR')
                        ->body('Kontrak '.$this->record->no_kontrak.' kini berada di menu SPR.')
                        ->send();
                    $this->redirect(KontrakResource::getUrl('index'));
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /** Grup "Bentuk Ulang dari Master" — dipakai juga oleh edit SPR. */
    protected function regenAction(): Actions\ActionGroup
    {
        return Actions\ActionGroup::make([
            Actions\Action::make('genTagihan')->label('Jadwal Tagihan ← Skema Bayar')
                ->icon('heroicon-o-calendar-days')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Jadwal Tagihan dari Skema Bayar kontrak. Baris tagihan lama diganti.')
                ->action(fn () => $this->regen('tagihan')),
            Actions\Action::make('genKomisi')->label('Jadwal Komisi ← Skema Komisi')
                ->icon('heroicon-o-banknotes')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Jadwal Komisi dari Skema Komisi yang berlaku. Hanya baris berstatus DRAFT yang diganti.')
                ->action(fn () => $this->regen('komisi')),
            Actions\Action::make('genGimmick')->label('Gimmick ← Setup Gimmick')
                ->icon('heroicon-o-gift')
                ->requiresConfirmation()
                ->modalDescription('Bentuk ulang Gimmick dari master Setup Gimmick. Baris gimmick lama diganti.')
                ->action(fn () => $this->regen('gimmick')),
        ])->label('Bentuk Ulang dari Master')->icon('heroicon-o-sparkles')->button();
    }

    /** Generate ulang satu tab dari masternya, lalu muat ulang form. */
    protected function regen(string $which): void
    {
        $svc = app(KontrakGeneratorService::class);
        $n = match ($which) {
            'tagihan' => $svc->tagihan($this->record),
            'komisi' => $svc->komisi($this->record),
            'gimmick' => $svc->gimmick($this->record),
            default => 0,
        };

        $this->record->refresh();
        $this->fillForm();

        Notification::make()
            ->title($n > 0 ? "Berhasil membentuk {$n} baris." : 'Tidak ada data master yang cocok.')
            ->{$n > 0 ? 'success' : 'warning'}()
            ->body($which === 'komisi' && $n === 0 ? 'Pastikan Skema Komisi & Agent kontrak sudah di-set.' : null)
            ->send();
    }
}
