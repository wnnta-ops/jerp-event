<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentSchedule
 */
class PaymentScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'installment_no' => $this->installment_no,
            'label'          => $this->label,
            'due_date'       => $this->due_date?->toDateString(),
            'amount'         => (float) $this->amount,
            'paid_amount'    => (float) $this->paid_amount,
            'outstanding'    => (float) $this->amount - (float) $this->paid_amount,
            'status'         => $this->status,
            'paid_at'        => $this->paid_at?->toIso8601String(),
        ];
    }
}
