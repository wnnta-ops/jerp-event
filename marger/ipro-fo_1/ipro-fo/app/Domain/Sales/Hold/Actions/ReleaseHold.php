<?php

declare(strict_types=1);

namespace App\Domain\Sales\Hold\Actions;

use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Hold\Models\UnitHold;
use Illuminate\Support\Facades\DB;

final class ReleaseHold
{
    public function __construct(private readonly UnitService $unitService) {}

    public function execute(UnitHold $hold, string $reason = 'manual_release'): void
    {
        DB::transaction(function () use ($hold, $reason): void {
            $hold->update(['status' => $reason === 'expired' ? 'expired' : 'released']);

            $unit = Unit::query()->lockForUpdate()->findOrFail($hold->unit_id);
            if ($unit->status === UnitStatus::Hold) {
                $this->unitService->changeStatus($unit, UnitStatus::Available);
            }
        });
    }
}
