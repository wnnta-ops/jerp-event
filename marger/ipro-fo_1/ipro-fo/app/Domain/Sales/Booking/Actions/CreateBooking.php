<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Booking\Services\PricingCalculator;
use App\Domain\Sales\Booking\Services\ScheduleGenerator;
use App\Domain\Sales\Hold\Models\UnitHold;
use Illuminate\Support\Facades\DB;

final class CreateBooking
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly UnitService $unitService,
        private readonly BookingService $bookingService,
        private readonly PricingCalculator $pricing,
        private readonly ScheduleGenerator $scheduleGen,
    ) {}

    public function execute(BookingDto $dto): Booking
    {
        return DB::transaction(function () use ($dto): Booking {
            $unit = Unit::query()->lockForUpdate()->findOrFail($dto->unitId);

            // Lock & validasi bookable
            $this->unitService->ensureBookable($unit, $dto->customerId);

            // Hitung harga
            $gimmickValue = (float) DB::table('gimmicks')
                ->whereIn('id', $dto->gimmickIds)
                ->sum('value');

            $price = $this->pricing->calculate(
                basePrice:      (float) $unit->current_price,
                discountPct:    $dto->discountPct,
                discountAmount: $dto->discountAmount,
                gimmickValue:   $gimmickValue,
                ppnPct:         (float) config('domain.tax.ppn_default_pct', 11),
            );

            $code = $this->numerator->next('BOOK', [
                'PROJECT' => DB::table('projects')->where('id', $dto->projectId)->value('code') ?? 'GEN',
            ]);

            // Tentukan status awal: kalau ada diskon di luar threshold auto → DRAFT (tunggu approval),
            // selain itu → ACTIVE
            $needsApproval = $this->bookingService->resolveDiscountApprover($dto->discountPct) !== null;
            $initialStatus = $needsApproval ? BookingStatus::Draft : BookingStatus::Active;

            $booking = Booking::create([
                'project_id'          => $dto->projectId,
                'booking_number'      => $code,
                'customer_id'         => $dto->customerId,
                'unit_id'             => $dto->unitId,
                'payment_scheme_id'   => $dto->paymentSchemeId,
                'sales_person_id'     => $dto->salesPersonId,
                'agent_id'            => $dto->agentId,
                'nup_registration_id' => $dto->nupRegistrationId,
                'booking_date'        => $dto->bookingDate,
                'base_price'          => $price['base'],
                'discount_amount'     => $price['discount'],
                'discount_pct'        => $dto->discountPct,
                'gimmick_value'       => $price['gimmick'],
                'ppn_pct'             => config('domain.tax.ppn_default_pct', 11),
                'ppn_amount'          => $price['ppn'],
                'total_price'         => $price['total'],
                'booking_source'      => $dto->bookingSource,
                'status'              => $initialStatus,
                'notes'               => $dto->notes,
                'created_by'          => auth()->id(),
            ]);

            // History entry untuk DRAFT awal
            \App\Domain\Sales\Booking\Models\BookingStatusHistory::create([
                'booking_id'  => $booking->id,
                'from_status' => null,
                'to_status'   => $initialStatus->value,
                'changed_by'  => auth()->id(),
                'notes'       => $needsApproval ? 'Menunggu approval diskon.' : 'Booking auto-confirmed.',
                'changed_at'  => now(),
            ]);

            // Generate jadwal kalau langsung ACTIVE
            if ($initialStatus === BookingStatus::Active) {
                $this->scheduleGen->generate($booking);
                $this->unitService->changeStatus($unit, UnitStatus::Booked);

                // Tutup hold kalau ada
                UnitHold::query()
                    ->where('unit_id', $unit->id)
                    ->where('customer_id', $dto->customerId)
                    ->where('status', 'active')
                    ->update(['status' => 'converted']);
            }

            event(new \App\Domain\Sales\Booking\Events\BookingCreated($booking, $needsApproval));

            return $booking;
        });
    }
}
