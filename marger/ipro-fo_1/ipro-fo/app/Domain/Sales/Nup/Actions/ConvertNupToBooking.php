<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class ConvertNupToBooking
{
    public function __construct(private readonly CreateBooking $createBooking) {}

    public function execute(NupRegistration $reg, BookingDto $dto): Booking
    {
        if ($reg->status !== 'paid') {
            throw new BusinessRuleException(
                'NUP harus PAID untuk dikonversi ke booking.',
                errorCode: 'NUP_NOT_PAID',
            );
        }

        return DB::transaction(function () use ($reg, $dto): Booking {
            // Make sure DTO carries the NUP linkage
            $bookingDto = new BookingDto(
                projectId:          $dto->projectId,
                customerId:         $reg->customer_id,
                unitId:             $dto->unitId,
                paymentSchemeId:    $dto->paymentSchemeId,
                salesPersonId:      $dto->salesPersonId,
                agentId:            $dto->agentId,
                nupRegistrationId:  $reg->id,
                bookingDate:        $dto->bookingDate,
                discountPct:        $dto->discountPct,
                discountAmount:     $dto->discountAmount,
                gimmickIds:         $dto->gimmickIds,
                notes:              $dto->notes,
                bookingSource:      'nup',
            );

            $booking = $this->createBooking->execute($bookingDto);

            $reg->update(['status' => 'converted']);

            return $booking;
        });
    }
}
