<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.booking.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason'         => ['required', 'string', 'min:10', 'max:500'],
            'penalty_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
