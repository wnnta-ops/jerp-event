<?php

declare(strict_types=1);

namespace App\Domain\Sales\Hold\Actions;

use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Hold\Models\UnitHold;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class HoldUnit
{
    public function __construct(private readonly UnitService $unitService) {}

    public function execute(int $unitId, int $customerId, ?int $salesPersonId = null, int $durationMinutes = 10_080, ?string $reason = null): UnitHold
    {
        return DB::transaction(function () use ($unitId, $customerId, $salesPersonId, $durationMinutes, $reason): UnitHold {
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);

            if (! in_array($unit->status, [UnitStatus::Available], true)) {
                throw new BusinessRuleException(
                    "Unit {$unit->unit_code} tidak dapat di-hold (status: {$unit->status->label()}).",
                    errorCode: 'UNIT_NOT_HOLDABLE',
                );
            }

            $maxDays = (int) config('domain.hold.max_days', 14);
            if ($durationMinutes > $maxDays * 24 * 60) {
                throw new BusinessRuleException(
                    "Durasi hold melebihi maksimal {$maxDays} hari.",
                    errorCode: 'HOLD_DURATION_EXCEEDED',
                );
            }

            $hold = UnitHold::create([
                'unit_id'         => $unit->id,
                'customer_id'     => $customerId,
                'sales_person_id' => $salesPersonId,
                'held_at'         => now(),
                'expires_at'      => now()->addMinutes($durationMinutes),
                'reason'          => $reason,
                'status'          => 'active',
                'created_by'      => auth()->id(),
            ]);

            $this->unitService->changeStatus($unit, UnitStatus::Hold);

            return $hold;
        });
    }
}
