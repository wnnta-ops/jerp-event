<?php

declare(strict_types=1);

namespace App\Domain\Legal\Bast\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Legal\Ajb\Models\AjbDocument;
use App\Domain\Legal\Bast\Models\BastDocument;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class IssueBast
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /** @param array<string, bool> $checklist */
    public function execute(Booking $booking, string $handedOverAt, array $checklist = []): BastDocument
    {
        // Kunci (disamakan dgn AJB): unit hanya bisa di-serahterima (BAST) bila sudah
        // AKAD (AJB terbit) DAN LUNAS (tak ada tagihan outstanding). Belum salah satu → tolak.
        if (! AjbDocument::where('booking_id', $booking->id)->exists()) {
            throw new BusinessRuleException(
                'Booking belum akad (AJB belum terbit). BAST tidak dapat diterbitkan.',
                errorCode: 'BOOKING_NOT_YET_AJB',
            );
        }

        $outstanding = DB::table('payment_schedules')
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['outstanding', 'partial', 'overdue'])
            ->sum(DB::raw('amount - paid_amount'));

        if ($outstanding > 0) {
            throw new BusinessRuleException(
                'Booking belum lunas (outstanding > 0). BAST tidak dapat diterbitkan.',
                errorCode: 'BOOKING_NOT_FULLY_PAID',
            );
        }

        return BastDocument::create([
            'booking_id'      => $booking->id,
            'bast_number'     => $this->numerator->next('BAST'),
            'handed_over_at'  => $handedOverAt,
            'checklist'       => $checklist ?: [
                'kunci_diterima'  => false,
                'unit_kondisi_baik' => false,
                'utilitas_aktif'  => false,
            ],
            'status'          => 'active',
        ]);
    }
}
