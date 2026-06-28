<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\DTOs;

use App\Domain\Finance\Tts\Enums\PaymentMethod;

final readonly class TtsDto
{
    public function __construct(
        public int $customerId,
        public ?int $bookingId,
        public float $amount,
        public PaymentMethod $paymentMethod,
        public string $receivedDate,
        public ?int $bankAccountId = null,
        public ?string $referenceNo = null,
        public ?string $notes = null,
        public ?string $giroNumber = null,
        public ?string $giroBank = null,
        public ?string $giroDate = null,
        public ?string $giroDueDate = null,
    ) {}

    public static function fromArray(array $p): self
    {
        return new self(
            customerId:     (int) $p['customer_id'],
            bookingId:      isset($p['booking_id']) ? (int) $p['booking_id'] : null,
            amount:         (float) $p['amount'],
            paymentMethod:  PaymentMethod::from($p['payment_method']),
            receivedDate:   (string) $p['received_date'],
            bankAccountId:  isset($p['bank_account_id']) ? (int) $p['bank_account_id'] : null,
            referenceNo:    $p['reference_no'] ?? null,
            notes:          $p['notes']        ?? null,
            giroNumber:     $p['giro_number']  ?? null,
            giroBank:       $p['giro_bank']    ?? null,
            giroDate:       $p['giro_date']    ?? null,
            giroDueDate:    $p['giro_due_date']?? null,
        );
    }
}
