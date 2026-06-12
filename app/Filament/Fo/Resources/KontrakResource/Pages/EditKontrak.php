<?php

namespace App\Filament\Fo\Resources\KontrakResource\Pages;

use App\Filament\Fo\Resources\KontrakResource;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditKontrak extends EditRecord
{
    protected static string $resource = KontrakResource::class;

    /**
     * SPR (final) wajib balance: total Tagihan + Biaya HARUS sama dengan Nilai
     * Kontrak (harga unit) — selisih 0. Bila tidak, batalkan simpan. SPRS (draft)
     * masih boleh belum pas karena sedang disusun.
     */
    protected function beforeSave(): void
    {
        if ($this->record->tahap !== 'SPR') {
            return;
        }

        $nilaiKontrak = (float) ($this->data['total_kontrak'] ?? $this->record->total_kontrak ?? 0);
        $calc = KontrakResource::hitungTagihan($this->data['tagihan'] ?? [], $nilaiKontrak);

        if (abs($calc['selisih']) >= 1) {
            $rp = fn (float $v) => 'Rp '.number_format($v, 0, ',', '.');
            Notification::make()->danger()->persistent()
                ->title('Tidak bisa simpan — tagihan tidak pas dengan Nilai Kontrak')
                ->body('Nilai Kontrak '.$rp($nilaiKontrak).' · Tagihan + Biaya '.$rp($calc['total'])
                    .' · Selisih '.$rp($calc['selisih']).'. Sesuaikan agar selisih 0.')
                ->send();
            $this->halt();
        }
    }

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
                    // Unit jadi TERJUAL hanya saat kontrak final (SPR); di SPRS masih BOOKING.
                    if ($this->record->unit_id && $unit = Unit::find($this->record->unit_id)) {
                        $unit->update(['status' => 'TERJUAL']);
                    }
                    Notification::make()->success()
                        ->title('Disimpan sebagai SPR')
                        ->body('Kontrak '.$this->record->no_kontrak.' kini SPR — unit ditandai TERJUAL.')
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
