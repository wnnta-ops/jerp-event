<?php

declare(strict_types=1);

namespace App\Domain\Legal\Ajb\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Legal\Ajb\Models\AjbDocument;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class IssueAjb
{
    public function __construct(private readonly NumeratorService $numerator) {}

    public function execute(Booking $booking, string $notaryName, string $signedAt): AjbDocument
    {
        $outstanding = DB::table('payment_schedules')
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['outstanding', 'partial', 'overdue'])
            ->sum(DB::raw('amount - paid_amount'));

        if ($outstanding > 0) {
            throw new BusinessRuleException(
                'Booking belum lunas (outstanding > 0). AJB tidak dapat diterbitkan.',
                errorCode: 'BOOKING_NOT_FULLY_PAID',
            );
        }

        return AjbDocument::create([
            'booking_id'  => $booking->id,
            'ajb_number'  => $this->numerator->next('AJB'),
            'signed_at'   => $signedAt,
            'notary_name' => $notaryName,
            'status'      => 'active',
        ]);
    }
}
