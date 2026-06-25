<?php

namespace App\Events;

use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast saat visibilitas unit berubah (is_active enable/disable — "hold internal").
 * Disable → siteplan/siteplanlive HILANGKAN unit + kurangi stok real-time; enable →
 * MUNCULKAN kembali (bawa coords/status/label/tahap untuk render ulang tanpa reload).
 * Channel publik siteplan.{projectId} (sama dgn UnitStatusChanged).
 */
class UnitVisibilityChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly int $unitId,
        public readonly bool $isActive,
        public readonly ?int $lokasiId = null,
        public readonly ?string $tahap = null,
        public readonly ?string $status = null,
        public readonly ?string $label = null,
        public readonly ?array $coords = null,
    ) {}

    public static function fromUnit(Unit $unit): self
    {
        return new self(
            projectId: (int) $unit->project_id,
            unitId: (int) $unit->id,
            isActive: (bool) $unit->is_active,
            lokasiId: $unit->lokasi_id,
            tahap: $unit->tahap,
            status: $unit->status,
            label: $unit->kode_unit ?: trim(($unit->blok ? $unit->blok.' / ' : '').($unit->no_unit ?? '')),
            coords: is_array($unit->siteplan_coords) ? $unit->siteplan_coords : null,
        );
    }

    /** @return array<int,Channel|PresenceChannel> */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("project.{$this->projectId}.units"),
            new Channel("siteplan.{$this->projectId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'unit.visibility.changed';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->unitId,
            'project_id' => $this->projectId,
            'is_active' => $this->isActive,
            'lokasi_id' => $this->lokasiId,
            'tahap' => $this->tahap,
            'status' => $this->status,
            'label' => $this->label,
            'coords' => $this->coords,
        ];
    }
}
