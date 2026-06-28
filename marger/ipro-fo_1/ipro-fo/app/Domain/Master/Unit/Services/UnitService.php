<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Services;

use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Repositories\UnitRepository;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class UnitService
{
    public function __construct(private readonly UnitRepository $repo) {}

    public function paginate(array $filters = [], int $perPage = 50)
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function forSiteplan(int $projectId)
    {
        return $this->repo->forSiteplan($projectId);
    }

    /**
     * Pastikan unit AVAIL atau di-hold oleh customer terkait sebelum transisi ke BOOKED.
     */
    public function ensureBookable(Unit $unit, int $customerId): void
    {
        if ($unit->status === UnitStatus::Available) {
            return;
        }
        if ($unit->status === UnitStatus::Hold) {
            $activeHold = \App\Domain\Sales\Hold\Models\UnitHold::query()
                ->where('unit_id', $unit->id)
                ->where('status', 'active')
                ->where('customer_id', $customerId)
                ->exists();
            if ($activeHold) {
                return;
            }
        }
        throw new BusinessRuleException(
            "Unit {$unit->unit_code} tidak tersedia untuk dibooking (status: {$unit->status->label()}).",
            errorCode: 'UNIT_NOT_BOOKABLE',
            details: ['unit_id' => $unit->id, 'status' => $unit->status->value],
        );
    }

    public function changeStatus(Unit $unit, UnitStatus $newStatus): void
    {
        DB::transaction(function () use ($unit, $newStatus): void {
            $unit->update(['status' => $newStatus]);

            broadcast(new \App\Domain\Master\Unit\Events\UnitStatusChanged(
                projectId: $unit->project_id,
                unitId:    $unit->id,
                newStatus: $newStatus->value,
            ))->toOthers();
        });
    }
}
