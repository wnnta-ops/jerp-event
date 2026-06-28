<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance\Tts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTtsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.tts.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id'    => ['required', 'integer', 'exists:customers,id'],
            'booking_id'     => ['nullable', 'integer', 'exists:bookings,id'],
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'transfer', 'giro', 'va'])],
            'received_date'  => ['required', 'date', 'before_or_equal:today'],
            'bank_account_id'=> ['nullable', 'integer', 'exists:bank_accounts,id'],
            'reference_no'   => ['nullable', 'string', 'max:255'],
            'notes'          => ['nullable', 'string', 'max:1000'],

            // Giro-specific
            'giro_number'    => ['required_if:payment_method,giro', 'nullable', 'string', 'max:64'],
            'giro_bank'      => ['required_if:payment_method,giro', 'nullable', 'string', 'max:64'],
            'giro_date'      => ['nullable', 'date'],
            'giro_due_date'  => ['required_if:payment_method,giro', 'nullable', 'date', 'after_or_equal:received_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min'                  => 'Nominal harus lebih dari 0.',
            'giro_number.required_if'     => 'Nomor giro wajib diisi untuk pembayaran via Bilyet Giro.',
            'giro_due_date.required_if'   => 'Jatuh tempo giro wajib diisi.',
        ];
    }
}
