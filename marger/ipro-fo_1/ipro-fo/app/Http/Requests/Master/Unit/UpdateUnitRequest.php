<?php

declare(strict_types=1);

namespace App\Http\Requests\Master\Unit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('master.unit.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'cluster_id'    => ['nullable', 'integer', 'exists:clusters,id'],
            'block_id'      => ['nullable', 'integer', 'exists:blocks,id'],
            'unit_type_id'  => ['nullable', 'integer', 'exists:unit_types,id'],
            'address'       => ['nullable', 'string', 'max:255'],
            'base_price'    => ['sometimes', 'numeric', 'min:0'],
            'current_price' => ['sometimes', 'numeric', 'min:0'],
            'status'        => ['sometimes', Rule::in(['available', 'hold', 'booked', 'sold', 'cancelled', 'maintenance'])],
            'attributes'    => ['nullable', 'array'],
        ];
    }
}
