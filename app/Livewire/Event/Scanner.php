<?php

namespace App\Livewire\Event;

use App\Models\User;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Policies\EventPolicy;
use App\Modules\Event\Services\AntreanService;
use App\Modules\Event\Services\ScanService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Scanner HP (/event/scan). Pilih event → titik scan (diingat di localStorage),
 * lalu scan QR customer dengan kamera atau ketik No. NUP.
 *
 * Semua aturan ada di ScanService (akses dicek ulang di server pada SETIAP
 * scan); komponen ini hanya meneruskan dan menampilkan hasil.
 */
class Scanner extends Component
{
    public ?int $eventId = null;

    public ?int $scanPointId = null;

    public function mount(): void
    {
        $events = $this->policy()->scannerEvents($this->user());
        if ($events->count() === 1) {
            $this->pilihEvent((int) $events->first()->id);
        }
    }

    public function pilihEvent(int $id): void
    {
        $this->eventId = $this->policy()->scannerEvents($this->user())->contains('id', $id) ? $id : null;
        $this->scanPointId = null;

        $titik = $this->titikTersedia();
        if ($titik->count() === 1) {
            $this->scanPointId = (int) $titik->first()->id;
        }
    }

    /** Dipanggil juga dari JS untuk memulihkan titik yang tersimpan di localStorage. */
    public function pilihTitik(int $id): bool
    {
        $point = EtScanPoint::query()->with('station')->find($id);
        if (! $point) {
            return false;
        }
        $eventId = (int) $point->station->event_id;
        if (! $this->policy()->scannerEvents($this->user())->contains('id', $eventId)) {
            return false;
        }

        $this->eventId = $eventId;
        if (! $this->titikTersedia()->contains('id', $id)) {
            return false;
        }
        $this->scanPointId = $id;

        return true;
    }

    public function gantiTitik(): void
    {
        $this->scanPointId = null;
    }

    public function gantiEvent(): void
    {
        $this->eventId = null;
        $this->scanPointId = null;
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function scan(string $payload, ?int $nupRegistrationId = null): array
    {
        if (! $this->scanPointId) {
            return ['status' => 'rejected', 'message' => 'Pilih titik scan dulu'];
        }

        return app(ScanService::class)
            ->handle($this->scanPointId, $payload, $this->user(), $nupRegistrationId, request()->userAgent())
            ->toArray();
    }

    /** @return array<string, mixed> */
    public function selesai(int $participantId): array
    {
        if (! $this->scanPointId) {
            return ['status' => 'rejected', 'message' => 'Pilih titik scan dulu'];
        }

        return app(ScanService::class)
            ->selesaikanManual($this->scanPointId, $participantId, $this->user(), request()->userAgent())
            ->toArray();
    }

    /** @return array<string, mixed> */
    public function undo(int $logId): array
    {
        return app(ScanService::class)->undo($logId, $this->user())->toArray();
    }

    public function render()
    {
        $user = $this->user();
        $events = $this->policy()->scannerEvents($user);
        $event = $this->eventId ? $events->firstWhere('id', $this->eventId) : null;
        if (! $event) {
            $this->eventId = null;
            $this->scanPointId = null;
        }

        $titik = $event ? $this->titikTersedia() : collect();
        $point = $this->scanPointId ? $titik->firstWhere('id', $this->scanPointId) : null;
        if (! $point) {
            $this->scanPointId = null;
        }

        $antrean = app(AntreanService::class);
        $menunggu = $dilayani = $milikSaya = [];
        if ($point) {
            if ($point->kind !== EtScanPoint::KELUAR) {
                $menunggu = $antrean->menunggu($point->station);
            }
            $semuaDilayani = $antrean->dilayani($point->station);
            // Satu petugas pegang satu customer: yang DIA scan Masuk tampil di panel
            // "Customer Anda" dengan slider selesai (titik Masuk & single).
            if ($point->kind !== EtScanPoint::KELUAR) {
                $milikSaya = array_values(array_filter($semuaDilayani, fn ($d) => $d['started_by'] === (int) $user->id));
            }
            if ($point->kind !== EtScanPoint::MASUK) {
                $dilayani = array_values(array_filter($semuaDilayani, fn ($d) => ! in_array($d, $milikSaya, true)));
            }
        }

        return view('livewire.event.scanner', [
            'events' => $events,
            'daftarEvent' => $this->policy()->scannerEventsTermasukDraft($user),
            'event' => $event,
            'titik' => $titik,
            'point' => $point,
            'menunggu' => $menunggu,
            'dilayani' => $dilayani,
            'milikSaya' => $milikSaya,
            'undoSeconds' => ScanService::UNDO_SECONDS,
        ])->layout('layouts.event-scanner', ['title' => $point ? $point->station->name.' · '.$point->kindLabel() : 'Scanner Event']);
    }

    /** @return Collection<int, EtScanPoint> */
    private function titikTersedia(): Collection
    {
        $event = $this->eventId ? EtEvent::query()->find($this->eventId) : null;

        return $event ? $this->policy()->scanPointsFor($this->user(), $event) : collect();
    }

    private function policy(): EventPolicy
    {
        return app(EventPolicy::class);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
