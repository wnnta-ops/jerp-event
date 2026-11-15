<?php

namespace App\Filament\Event\Resources\EventResource\Pages;

use App\Filament\Event\Resources\EventResource;
use App\Models\User;
use App\Modules\Event\Services\EventDraftService;
use App\Modules\Event\Services\EventSetupService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Wizard buat event. "Simpan draft" menyimpan isi form (belum divalidasi) untuk
 * akun yang sedang login; membuka halaman ini lagi otomatis melanjutkan draft itu.
 */
class CreateEvent extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Buat event';

    protected function getSteps(): array
    {
        return EventResource::wizardSteps();
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        $draft = $this->drafts()->ambil($this->user());
        if ($draft !== null) {
            $this->form->fill($draft->data);
            Notification::make()->info()->title('Melanjutkan draft')
                ->body('Disimpan '.$draft->updated_at->diffForHumans().'. Pilih "Buang draft" untuk mulai dari kosong.')
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('simpanDraft')
                ->label('Simpan draft')
                ->icon('heroicon-o-bookmark')
                ->color('gray')
                ->action(function () {
                    $this->drafts()->simpan($this->user(), $this->form->getRawState());
                    Notification::make()->success()->title('Draft tersimpan')
                        ->body('Hanya terlihat oleh akun Anda. Buka "Buat event" lagi untuk melanjutkan.')
                        ->send();
                }),
            Action::make('buangDraft')
                ->label('Buang draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Isi draft yang tersimpan dihapus dan form kembali kosong.')
                ->visible(fn () => $this->drafts()->ambil($this->user()) !== null)
                ->action(function () {
                    $this->drafts()->buang($this->user());
                    $this->form->fill();
                    Notification::make()->success()->title('Draft dibuang')->send();
                }),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EventSetupService::class)->create($data, $this->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Event belum tersimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    /** Event jadi → draft tak diperlukan lagi. */
    protected function afterCreate(): void
    {
        $this->drafts()->buang($this->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function drafts(): EventDraftService
    {
        return app(EventDraftService::class);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
