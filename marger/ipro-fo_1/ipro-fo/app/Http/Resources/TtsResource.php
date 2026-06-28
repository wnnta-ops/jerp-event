<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Finance\Tts\Models\Tts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tts
 */
class TtsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'tts_number'      => $this->tts_number,
            'amount'          => (float) $this->amount,
            'payment_method'  => $this->payment_method,
            'received_date'   => $this->received_date?->toDateString(),
            'reference_no'    => $this->reference_no,
            'status'          => $this->status,
            'notes'           => $this->notes,
            'customer'        => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code, 'full_name' => $this->customer->full_name,
            ]),
            'booking'         => $this->whenLoaded('booking', fn () => $this->booking ? [
                'id' => $this->booking->id, 'booking_number' => $this->booking->booking_number,
            ] : null),
            'allocations'     => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'payment_schedule_id' => $a->payment_schedule_id, 'amount' => (float) $a->amount,
            ])),
            'giro'            => $this->whenLoaded('giro', fn () => $this->giro ? [
                'giro_number'  => $this->giro->giro_number,
                'issuing_bank' => $this->giro->issuing_bank,
                'due_date'     => $this->giro->due_date?->toDateString(),
                'status'       => $this->giro->status,
            ] : null),
            'posted_at'       => $this->posted_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
