<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\NameChange\Actions;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Exception\NameChange\Models\NameChangeRequest;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class RequestNameChange
{
    public function __construct(private readonly SubmitForApproval $submit) {}

    public function execute(Booking $booking, int $newCustomerId, float $adminFee = 0): NameChangeRequest
    {
        // Validasi: belum AJB & tidak ada tunggakan
        $hasOverdue = DB::table('payment_schedules')
            ->where('booking_id', $booking->id)
            ->where('status', 'overdue')
            ->exists();

        if ($hasOverdue) {
            throw new BusinessRuleException(
                'Tidak dapat ganti nama: ada tagihan overdue. Lunasi dulu.',
                errorCode: 'GN_HAS_OVERDUE',
            );
        }

        return DB::transaction(function () use ($booking, $newCustomerId, $adminFee): NameChangeRequest {
            $req = NameChangeRequest::create([
                'booking_id'      => $booking->id,
                'old_customer_id' => $booking->customer_id,
                'new_customer_id' => $newCustomerId,
                'requested_at'    => now()->toDateString(),
                'admin_fee'       => $adminFee,
                'status'          => 'pending',
            ]);

            $approval = $this->submit->execute('booking_gn', $req, [
                'booking_id'      => $booking->id,
                'old_customer_id' => $booking->customer_id,
                'new_customer_id' => $newCustomerId,
                'admin_fee'       => $adminFee,
            ]);

            $req->update(['approval_id' => $approval->id]);

            return $req;
        });
    }
}
