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

    /** @param  ?float  $amount  Nominal AJB (nilai transaksi pada akta); null = belum diisi. */
    public function execute(Booking $booking, string $notaryName, string $signedAt, ?float $amount = null): AjbDocument
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
            // Bila nominal tidak diisi operator, dipakai nilai transaksi booking-nya sebagai
            // nilai akta — bukan dibiarkan kosong, supaya baris tidak terbaca "BELUM BAST"
            // padahal aktanya sudah terbit.
            'amount'      => $amount ?? (float) $booking->total_price,
            'notary_name' => $notaryName,
            'status'      => 'active',
        ]);
    }
}
