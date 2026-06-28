<?php

declare(strict_types=1);

namespace App\Http\Requests\Master\Customer;

use Illuminate\Validation\Rule;

/**
 * Reuse seluruh aturan StoreCustomerRequest (65 field), tapi:
 *  - permission: master.customer.update
 *  - field wajib (full_name/id_type/id_number) jadi `sometimes` (partial update OK)
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('master.customer.update') ?? false;
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['full_name'] = ['sometimes', 'required', 'string', 'min:2', 'max:150'];
        $rules['id_type']   = ['sometimes', 'required', Rule::in(['KTP', 'Passport', 'SIM'])];
        $rules['id_number'] = $this->input('id_type') === 'KTP'
            ? ['sometimes', 'required', 'digits:16']
            : ['sometimes', 'required', 'string', 'min:6', 'max:32'];
        $rules['npwp']      = ['sometimes', 'required', 'digits:16'];
        $rules['phone']     = ['sometimes', 'required', 'digits_between:12,15'];

        return $rules;
    }
}
