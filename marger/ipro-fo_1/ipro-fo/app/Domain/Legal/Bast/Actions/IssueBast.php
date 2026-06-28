<?php

declare(strict_types=1);

namespace App\Domain\Legal\Bast\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Legal\Bast\Models\BastDocument;
use App\Domain\Sales\Booking\Models\Booking;

final class IssueBast
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /** @param array<string, bool> $checklist */
    public function execute(Booking $booking, string $handedOverAt, array $checklist = []): BastDocument
    {
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
