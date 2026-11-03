<?php

namespace App\Events;

use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a unit's status changes — drives UnitPetaLive (BR-LNC, RULE-06).
 * Dikirim SETELAH transaksi commit: bila transaksi batal (mis. pilih unit gagal), peta
 * yang sedang terbuka tidak ikut menampilkan status yang tak pernah tersimpan.
 * Channel: presence project.{projectId}.units
 * Replaces legacy SignalR unitHub (see docs/04_BusinessFlow.md §3).
 */
class UnitStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly int $unitId,
        public readonly string $newStatus,
        public readonly ?string $oldStatus = null,
        public readonly ?int $byUserId = null,
        public readonly ?string $byUserName = null,
        public readonly ?string $blok = null,
        public readonly ?string $noUnit = null,
    ) {}

    /**
     * Construct directly from a Unit model for convenience.
     */
    public static function fromUnit(Unit $unit, string $newStatus, ?string $oldStatus = null): self
    {
        $user = auth()->user();

        return new self(
            projectId: $unit->project_id,
            unitId: $unit->id,
            newStatus: $newStatus,
            oldStatus: $oldStatus,
            byUserId: $user?->id,
            byUserName: $user?->full_name ?? $user?->name,
            blok: $unit->blok,
            noUnit: $unit->no_unit,
        );
    }

    /**
     * @return array<int,Channel|PrivateChannel|PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            // Live map launching (butuh login) — operator hari-H.
            new PresenceChannel("project.{$this->projectId}.units"),
            // Site plan publik (marketing, tanpa login) — pengunjung lihat unit
            // terjual real-time. Channel publik: tidak perlu auth.
            new Channel("siteplan.{$this->projectId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'unit.status.changed';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->unitId,
            'project_id' => $this->projectId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'blok' => $this->blok,
            'no_unit' => $this->noUnit,
            'by_user_id' => $this->byUserId,
            'by_user_name' => $this->byUserName,
            'ts' => now()->toIso8601String(),
        ];
    }
}
