<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\Reschedule\Actions;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Exception\Reschedule\Models\RescheduleRequest;
use Illuminate\Support\Facades\DB;

final class RequestReschedule
{
    public function __construct(private readonly SubmitForApproval $submit) {}

    /**
     * @param array<int, array{id:int,new_due_date:string}> $proposed
     */
    public function execute(Booking $booking, array $proposed, float $adminFee = 0): RescheduleRequest
    {
        return DB::transaction(function () use ($booking, $proposed, $adminFee): RescheduleRequest {
            $req = RescheduleRequest::create([
                'booking_id'        => $booking->id,
                'proposed_schedule' => $proposed,
                'admin_fee'         => $adminFee,
                'status'            => 'pending',
            ]);

            $approval = $this->submit->execute('booking_reschedule', $req, [
                'booking_id' => $booking->id,
                'count'      => count($proposed),
                'admin_fee'  => $adminFee,
            ]);

            $req->update(['approval_id' => $approval->id]);
            return $req;
        });
    }
}
