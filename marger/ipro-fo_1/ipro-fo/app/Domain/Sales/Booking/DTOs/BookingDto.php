<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\DTOs;

final readonly class BookingDto
{
    /**
     * @param array<int, int> $gimmickIds
     */
    public function __construct(
        public int $projectId,
        public int $customerId,
        public int $unitId,
        public int $paymentSchemeId,
        public ?int $salesPersonId = null,
        public ?int $agentId = null,
        public ?int $nupRegistrationId = null,
        public string $bookingDate = '',
        public float $discountPct = 0,
        public float $discountAmount = 0,
        public array $gimmickIds = [],
        public ?string $notes = null,
        public string $bookingSource = 'direct',
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            projectId:          (int) $payload['project_id'],
            customerId:         (int) $payload['customer_id'],
            unitId:             (int) $payload['unit_id'],
            paymentSchemeId:    (int) $payload['payment_scheme_id'],
            salesPersonId:      isset($payload['sales_person_id']) ? (int) $payload['sales_person_id'] : null,
            agentId:            isset($payload['agent_id']) ? (int) $payload['agent_id'] : null,
            nupRegistrationId:  isset($payload['nup_registration_id']) ? (int) $payload['nup_registration_id'] : null,
            bookingDate:        (string) ($payload['booking_date'] ?? now()->toDateString()),
            discountPct:        (float) ($payload['discount_pct'] ?? 0),
            discountAmount:     (float) ($payload['discount_amount'] ?? 0),
            gimmickIds:         $payload['gimmick_ids'] ?? [],
            notes:              $payload['notes'] ?? null,
            bookingSource:      $payload['booking_source'] ?? 'direct',
        );
    }
}
