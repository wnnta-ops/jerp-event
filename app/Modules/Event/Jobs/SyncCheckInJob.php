<?php

namespace App\Modules\Event\Jobs;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Services\SyncCheckInService;
use App\Scopes\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tarik check-in FO (Aktivasi NUP) ke semua event yang sedang dibuka.
 *
 * Dijalankan tiap 10 detik oleh scheduler lewat `event:sinkron`. Event dibaca
 * lintas tenant (tanpa TenantScope); SyncCheckInService memasang TenantContext
 * per event sebelum membaca FO — tanpa itu query FO di cron kosong/salah tenant.
 * Idempoten: aman bila tumpang-tindih atau diulang.
 */
class SyncCheckInJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 60;

    public function __construct(public readonly ?int $eventId = null) {}

    public function uniqueId(): string
    {
        return 'event-sync-'.($this->eventId ?? 'semua');
    }

    /** @return array<int, array{event_id:int, baru:int, diperbarui:int, dibatalkan:int}> */
    public function handle(SyncCheckInService $sync): array
    {
        $events = EtEvent::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', EtEvent::OPEN)
            ->whereNotNull('fo_nup_event_id')   // event mandiri tak punya check-in FO
            ->when($this->eventId, fn ($q) => $q->whereKey($this->eventId))
            ->get();

        $hasil = [];
        foreach ($events as $event) {
            try {
                $hasil[] = ['event_id' => (int) $event->id] + $sync->syncEvent($event);
            } catch (Throwable $e) {
                // Satu event gagal tidak boleh menghentikan event lain.
                Log::warning('[event] sinkron check-in gagal', ['event_id' => $event->id, 'error' => $e->getMessage()]);
            }
        }

        return $hasil;
    }
}
