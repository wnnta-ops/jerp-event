<?php

namespace App\Filament\Event\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Models\User;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Policies\EventPolicy;
use App\Modules\Event\Services\AntreanService;
use App\Modules\Event\Services\KoreksiService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Live board: satu kolom per pos, siapa menunggu & siapa sedang dilayani, dengan
 * timer berjalan (kuning lewat target, merah lewat 1,5× target). Diperbarui tiap
 * 5 detik. Koreksi (tutup paksa, mulai layanan, tandai pulang, buka kembali)
 * wajib beralasan.
 *
 * Akses: izin halaman (config/akses.php → event.live_board) DAN hak data:
 * superadmin, atau can_live_board di event itu.
 */
class LiveBoard extends Page
{
    use MematuhiIzinHalaman {
        canAccess as protected izinHalaman;
    }

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Live board';

    protected static ?string $title = 'Live board';

    protected static ?string $slug = 'live-board';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.event.pages.live-board';

    #[Url(as: 'event')]
    public ?int $eventId = null;

    public string $cari = '';

    /** 'pos' = kolom per pos; 'peserta' = satu baris per peserta dengan titik per pos. */
    #[Url(as: 'tampilan')]
    public string $tampilan = 'pos';

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (! $user instanceof User || ! static::izinHalaman()) {
            return false;
        }

        return app(EventPolicy::class)->isSuper($user)
            || EtAccess::query()->where('user_id', $user->id)->where('can_live_board', true)->exists();
    }

    public function mount(): void
    {
        $events = $this->eventTersedia();
        if (! $this->eventId || ! $events->contains('id', $this->eventId)) {
            $this->eventId = $events->firstWhere('status', EtEvent::OPEN)?->id ?? $events->first()?->id;
        }
    }

    public function gantiTampilan(string $tampilan): void
    {
        $this->tampilan = in_array($tampilan, ['pos', 'peserta'], true) ? $tampilan : 'pos';
    }

    public function getSubheading(): ?string
    {
        return $this->event()?->name;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $event = $this->event();
        $perPeserta = $this->tampilan === 'peserta';

        return [
            'perPeserta' => $perPeserta,
            'perjalanan' => $event && $perPeserta ? app(AntreanService::class)->perjalanan($event, $this->cari) : null,
            'events' => $this->eventTersedia(),
            'event' => $event,
            'papan' => $event ? app(AntreanService::class)->papan($event) : null,
            'hasilCari' => $event ? $this->hasilCari($event) : collect(),
            'bolehKoreksi' => $event ? app(EventPolicy::class)->canCorrect($this->user(), $event) : false,
        ];
    }

    // ── Aksi koreksi (alasan wajib) ─────────────────────────────────────

    public function tutupKunjunganAction(): Action
    {
        return Action::make('tutupKunjungan')
            ->label('Tutup paksa')
            ->modalHeading('Tutup paksa kunjungan')
            ->modalDescription('Dipakai bila scan Keluar terlewat. Waktu selesai = sekarang.')
            ->form([$this->fieldAlasan()])
            ->action(fn (array $data, array $arguments) => $this->koreksi(fn (KoreksiService $k) => $k->tutupKunjungan(
                EtVisit::query()->findOrFail($arguments['visit']), $this->user(), $data['alasan'],
            ), 'Kunjungan ditutup'));
    }

    public function mulaiLayananAction(): Action
    {
        return Action::make('mulaiLayanan')
            ->label('Mulai layanan')
            ->modalHeading('Mulai layanan')
            ->modalDescription('Dipakai bila scan Masuk terlewat. Waktu mulai = sekarang; kunjungan lain yang masih terbuka ditutup.')
            ->form(fn () => [
                Forms\Components\Select::make('station')
                    ->label('Di pos')
                    ->options(fn () => $this->event()?->stations()->where('type', '!=', EtStation::FO_CHECKIN)->pluck('name', 'id') ?? [])
                    ->required(),
                $this->fieldAlasan(),
            ])
            ->fillForm(fn (array $arguments) => ['station' => $arguments['station'] ?? null])
            ->action(fn (array $data, array $arguments) => $this->koreksi(fn (KoreksiService $k) => $k->mulaiLayanan(
                EtParticipant::query()->findOrFail($arguments['participant']),
                EtStation::query()->findOrFail($data['station']),
                $this->user(), $data['alasan'],
            ), 'Layanan dimulai'));
    }

    public function tandaiPulangAction(): Action
    {
        return Action::make('tandaiPulang')
            ->label('Tandai pulang')
            ->color('danger')
            ->modalHeading('Tandai peserta pulang')
            ->modalDescription('Peserta tidak melanjutkan. Kunjungan yang masih terbuka ditutup.')
            ->form([$this->fieldAlasan()])
            ->action(fn (array $data, array $arguments) => $this->koreksi(fn (KoreksiService $k) => $k->tandaiPulang(
                EtParticipant::query()->findOrFail($arguments['participant']), $this->user(), $data['alasan'],
            ), 'Ditandai pulang'));
    }

    public function bukaKembaliAction(): Action
    {
        return Action::make('bukaKembali')
            ->label('Buka kembali')
            ->modalHeading('Aktifkan kembali peserta')
            ->modalDescription('Peserta kembali menunggu di pos berikutnya.')
            ->form([$this->fieldAlasan()])
            ->action(fn (array $data, array $arguments) => $this->koreksi(fn (KoreksiService $k) => $k->bukaKembali(
                EtParticipant::query()->findOrFail($arguments['participant']), $this->user(), $data['alasan'],
            ), 'Peserta aktif kembali'));
    }

    // ── bantu ───────────────────────────────────────────────────────────

    private function fieldAlasan(): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make('alasan')->label('Alasan koreksi')->required()->minLength(3)->rows(2)
            ->placeholder('mis. HP Keluar mati, customer sudah selesai 10:42');
    }

    private function koreksi(\Closure $aksi, string $judul): void
    {
        try {
            $aksi(app(KoreksiService::class));
            Notification::make()->success()->title($judul)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Koreksi gagal')->body($e->getMessage())->send();
        }
    }

    /** @return Collection<int, EtEvent> */
    private function eventTersedia(): Collection
    {
        $user = $this->user();
        $q = EtEvent::query()->whereIn('status', [EtEvent::OPEN, EtEvent::CLOSED])->orderByDesc('event_date')->orderByDesc('id');
        if (! app(EventPolicy::class)->isSuper($user)) {
            $q->whereIn('id', EtAccess::query()->select('event_id')->where('user_id', $user->id)->where('can_live_board', true));
        }

        return $q->get();
    }

    private function event(): ?EtEvent
    {
        $event = $this->eventId ? EtEvent::query()->find($this->eventId) : null;

        return $event && app(EventPolicy::class)->canLiveBoard($this->user(), $event) ? $event : null;
    }

    /** @return Collection<int, EtParticipant> */
    private function hasilCari(EtEvent $event): Collection
    {
        $q = trim($this->cari);
        if (mb_strlen($q) < 2) {
            return collect();
        }

        return EtParticipant::query()
            ->with('currentStation:id,name')
            ->where('event_id', $event->id)
            ->where(fn ($w) => $w->where('name_snapshot', 'like', "%{$q}%")
                ->orWhere('no_nup', 'like', "%{$q}%")
                ->orWhere('customer_code', 'like', "%{$q}%"))
            ->orderBy('customer_code')->orderBy('no_nup')
            ->limit(30)
            ->get();
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
