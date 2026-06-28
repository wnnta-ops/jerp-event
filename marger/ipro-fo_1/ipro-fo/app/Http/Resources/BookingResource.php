<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'booking_number'  => $this->booking_number,
            'booking_date'    => $this->booking_date?->toDateString(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'status_color'    => $this->status?->color(),
            'booking_source'  => $this->booking_source,
            'customer'        => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code,
                'full_name' => $this->customer->full_name, 'phone' => $this->customer->phone,
            ]),
            'unit'            => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id, 'unit_code' => $this->unit->unit_code,
                'current_price' => (float) $this->unit->current_price,
            ]),
            'payment_scheme'  => $this->whenLoaded('paymentScheme', fn () => [
                'id' => $this->paymentScheme->id, 'code' => $this->paymentScheme->code,
                'name' => $this->paymentScheme->name,
            ]),
            'pricing' => [
                'base'         => (float) $this->base_price,
                'discount_pct' => (float) $this->discount_pct,
                'discount_amt' => (float) $this->discount_amount,
                'gimmick'      => (float) $this->gimmick_value,
                'ppn'          => (float) $this->ppn_amount,
                'ppn_pct'      => (float) $this->ppn_pct,
                'total'        => (float) $this->total_price,
            ],
            'notes'           => $this->notes,
            'payment_schedules' => PaymentScheduleResource::collection($this->whenLoaded('paymentSchedules')),
            'status_histories' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($h) => [
                'from'    => $h->from_status,
                'to'      => $h->to_status,
                'notes'   => $h->notes,
                'changed_at' => $h->changed_at?->toIso8601String(),
            ])),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
