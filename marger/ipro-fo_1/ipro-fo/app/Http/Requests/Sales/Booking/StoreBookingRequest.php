<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.booking.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id'         => ['required', 'integer', 'exists:projects,id'],
            'customer_id'        => ['required', 'integer', 'exists:customers,id'],
            'unit_id'            => ['required', 'integer', 'exists:units,id'],
            'payment_scheme_id'  => ['required', 'integer', 'exists:payment_schemes,id'],
            'sales_person_id'    => ['nullable', 'integer', 'exists:sales_persons,id'],
            'agent_id'           => ['nullable', 'integer', 'exists:agents,id'],
            'nup_registration_id'=> ['nullable', 'integer', 'exists:nup_registrations,id'],
            'booking_date'       => ['required', 'date', 'before_or_equal:today'],
            'discount_pct'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'gimmick_ids'        => ['nullable', 'array'],
            'gimmick_ids.*'      => ['integer', 'exists:gimmicks,id'],
            'notes'              => ['nullable', 'string', 'max:1000'],
            'booking_source'     => ['nullable', Rule::in(['direct', 'nup', 'launching', 'agent'])],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required'        => 'Project wajib dipilih.',
            'customer_id.required'       => 'Customer wajib dipilih.',
            'unit_id.required'           => 'Unit wajib dipilih.',
            'payment_scheme_id.required' => 'Skema pembayaran wajib dipilih.',
            'booking_date.required'      => 'Tanggal booking wajib diisi.',
            'discount_pct.max'           => 'Diskon maksimal 100%.',
        ];
    }
}
