<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\UnitChange\Actions;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Exception\UnitChange\Models\UnitChangeRequest;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class RequestUnitChange
{
    public function __construct(private readonly SubmitForApproval $submit) {}

    public function execute(Booking $booking, int $newUnitId, float $adminFee = 0): UnitChangeRequest
    {
        $newUnit = Unit::lockForUpdate()->findOrFail($newUnitId);
        if ($newUnit->status !== UnitStatus::Available) {
            throw new BusinessRuleException(
                "Unit {$newUnit->unit_code} tidak available (status: {$newUnit->status->label()}).",
                errorCode: 'NEW_UNIT_NOT_AVAILABLE',
            );
        }

        $priceDiff = (float) $newUnit->current_price - (float) $booking->base_price;

        return DB::transaction(function () use ($booking, $newUnitId, $adminFee, $priceDiff): UnitChangeRequest {
            $req = UnitChangeRequest::create([
                'booking_id'  => $booking->id,
                'old_unit_id' => $booking->unit_id,
                'new_unit_id' => $newUnitId,
                'price_diff'  => $priceDiff,
                'admin_fee'   => $adminFee,
                'status'      => 'pending',
            ]);

            $approval = $this->submit->execute('booking_gu', $req, [
                'booking_id'  => $booking->id,
                'old_unit_id' => $booking->unit_id,
                'new_unit_id' => $newUnitId,
                'price_diff'  => $priceDiff,
            ]);

            $req->update(['approval_id' => $approval->id]);
            return $req;
        });
    }
}
