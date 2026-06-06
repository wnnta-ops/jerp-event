<?php

namespace App\Modules\FO\Launching\Services;

use App\Events\UnitStatusChanged;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atomic lock/unlock of a unit for the live launching map.
 * Implements RULE-06 (no double-booking) via SELECT ... FOR UPDATE.
 * On success, broadcasts UnitStatusChanged so all open maps update <1s.
 */
class UnitLockService
{
    /**
     * Reserve a TERSEDIA unit by setting status BOOKING.
     *
     * @throws RuntimeException when the unit is no longer available.
     */
    public function lock(int $unitId): Unit
    {
        return DB::transaction(function () use ($unitId) {
            /** @var Unit $unit */
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);
            $oldStatus = $unit->status;

            if ($oldStatus !== 'TERSEDIA') {
                throw new RuntimeException("Unit {$unit->blok}/{$unit->no_unit} sudah {$oldStatus}.");
            }

            $unit->update(['status' => 'BOOKING']);
            event(UnitStatusChanged::fromUnit($unit->fresh(), 'BOOKING', $oldStatus));

            return $unit->fresh();
        });
    }

    /**
     * Release a BOOKING unit back to TERSEDIA (e.g. booking expired / cancelled).
     */
    public function release(int $unitId): Unit
    {
        return DB::transaction(function () use ($unitId) {
            /** @var Unit $unit */
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);
            $oldStatus = $unit->status;

            if (! in_array($oldStatus, ['BOOKING', 'HOLD'], true)) {
                throw new RuntimeException("Unit {$unit->blok}/{$unit->no_unit} tidak dalam status BOOKING/HOLD.");
            }

            $unit->update(['status' => 'TERSEDIA']);
            event(UnitStatusChanged::fromUnit($unit->fresh(), 'TERSEDIA', $oldStatus));

            return $unit->fresh();
        });
    }

    /**
     * Mark unit TERJUAL after closing succeeds.
     */
    public function sell(int $unitId): Unit
    {
        return DB::transaction(function () use ($unitId) {
            /** @var Unit $unit */
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);
            $oldStatus = $unit->status;

            if ($oldStatus === 'TERJUAL' || $oldStatus === 'LUNAS') {
                throw new RuntimeException("Unit sudah {$oldStatus}.");
            }

            $unit->update(['status' => 'TERJUAL']);
            event(UnitStatusChanged::fromUnit($unit->fresh(), 'TERJUAL', $oldStatus));

            return $unit->fresh();
        });
    }
}
