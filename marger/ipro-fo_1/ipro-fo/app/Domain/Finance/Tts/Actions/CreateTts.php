<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Actions;

use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Finance\Tts\Models\TtsGiro;
use App\Domain\Finance\Tts\Services\TtsAllocator;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class CreateTts
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly TtsAllocator $allocator,
    ) {}

    public function execute(TtsDto $dto, bool $autoAllocate = true): Tts
    {
        return DB::transaction(function () use ($dto, $autoAllocate): Tts {
            if ($dto->paymentMethod === PaymentMethod::Giro && (! $dto->giroNumber || ! $dto->giroDueDate)) {
                throw new BusinessRuleException(
                    'Nomor giro dan tanggal jatuh tempo wajib diisi untuk pembayaran via Bilyet Giro.',
                    errorCode: 'GIRO_REQUIRED_FIELDS',
                );
            }

            $code = $this->numerator->next('TTS');

            $tts = Tts::create([
                'project_id'      => null,
                'tts_number'      => $code,
                'customer_id'     => $dto->customerId,
                'booking_id'      => $dto->bookingId,
                'amount'          => $dto->amount,
                'payment_method'  => $dto->paymentMethod->value,
                'received_date'   => $dto->receivedDate,
                'bank_account_id' => $dto->bankAccountId,
                'reference_no'    => $dto->referenceNo,
                'status'          => $dto->paymentMethod === PaymentMethod::Giro ? 'pending' : 'verified',
                'notes'           => $dto->notes,
                'received_by'     => auth()->id(),
            ]);

            if ($dto->paymentMethod === PaymentMethod::Giro) {
                TtsGiro::create([
                    'tts_receipt_id' => $tts->id,
                    'giro_number'    => $dto->giroNumber,
                    'issuing_bank'   => $dto->giroBank,
                    'giro_date'      => $dto->giroDate,
                    'due_date'       => $dto->giroDueDate,
                    'status'         => 'open',
                ]);
            }

            // Auto-allocate untuk cash/transfer/VA (langsung verified)
            if ($autoAllocate && $dto->paymentMethod !== PaymentMethod::Giro && $dto->bookingId !== null) {
                $this->allocator->autoAllocate($tts);
            }

            return $tts->refresh();
        });
    }
}
