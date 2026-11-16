<?php

namespace App\Filament\Event\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Models\User;
use App\Modules\Event\Exports\EventExport;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Policies\EventPolicy;
use App\Modules\Event\Services\EventReportService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan satu event: ringkasan, per pos (rata-rata / median / P90), throughput per
 * jam, dan perjalanan per customer — plus export Excel dengan filter yang sama.
 *
 * Akses: izin halaman (config/akses.php → event.laporan) DAN hak data:
 * superadmin, atau can_reports di event itu.
 */
class Laporan extends Page
{
    use MematuhiIzinHalaman {
        canAccess as protected izinHalaman;
    }

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan event';

    protected static ?string $slug = 'laporan';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.event.pages.laporan';

    #[Url(as: 'event')]
    public ?int $eventId = null;

    /** Rentang waktu mulai kunjungan (datetime-local, zona waktu aplikasi). */
    public ?string $dari = null;

    public ?string $sampai = null;

    /** @var array<int, int|string> Id pos yang dicentang (Livewire bisa mengirim string). */
    public array $pos = [];

    /** @var array<int, string> */
    public array $status = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (! $user instanceof User || ! static::izinHalaman()) {
            return false;
        }

        return app(EventPolicy::class)->isSuper($user)
            || EtAccess::query()->where('user_id', $user->id)->where('can_reports', true)->exists();
    }

    public function mount(): void
    {
        $events = $this->eventTersedia();
        if (! $this->eventId || ! $events->contains('id', $this->eventId)) {
            $this->eventId = $events->first()?->id;
        }
    }

    public function updatedEventId(): void
    {
        $this->pos = [];
    }

    public function resetFilter(): void
    {
        $this->dari = $this->sampai = null;
        $this->pos = [];
        $this->status = [];
    }

    public function getSubheading(): ?string
    {
        return $this->event()?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $this->event() !== null)
                ->action(function () {
                    $event = $this->event();
                    abort_unless($event !== null, 403);

                    return Excel::download(
                        new EventExport($event, $this->filter()),
                        'laporan-event-'.Str::slug($event->name).'-'.now()->format('Ymd-Hi').'.xlsx',
                    );
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $event = $this->event();
        $r = app(EventReportService::class);
        $f = $this->filter();

        return [
            'events' => $this->eventTersedia(),
            'event' => $event,
            'stations' => $event ? EtStation::query()->where('event_id', $event->id)->where('type', '!=', EtStation::FO_CHECKIN)->orderBy('sequence')->get() : collect(),
            'ringkasan' => $event ? $r->ringkasan($event, $f) : null,
            'perPos' => $event ? $r->perPos($event, $f) : [],
            'throughput' => $event ? $r->throughputPerJam($event, $f) : [],
            'perCustomer' => $event ? $r->perCustomer($event, $f) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function filter(): array
    {
        return [
            'dari' => $this->dari ?: null,
            'sampai' => $this->sampai ?: null,
            'pos' => array_values(array_map('intval', $this->pos)),
            'status' => array_values($this->status),
        ];
    }

    /** @return Collection<int, EtEvent> */
    private function eventTersedia(): Collection
    {
        $user = $this->user();
        $q = EtEvent::query()->whereIn('status', [EtEvent::OPEN, EtEvent::CLOSED])->orderByDesc('event_date')->orderByDesc('id');
        if (! app(EventPolicy::class)->isSuper($user)) {
            $q->whereIn('id', EtAccess::query()->select('event_id')->where('user_id', $user->id)->where('can_reports', true));
        }

        return $q->get();
    }

    private function event(): ?EtEvent
    {
        $event = $this->eventId ? EtEvent::query()->find($this->eventId) : null;

        return $event && app(EventPolicy::class)->canReports($this->user(), $event) ? $event : null;
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
