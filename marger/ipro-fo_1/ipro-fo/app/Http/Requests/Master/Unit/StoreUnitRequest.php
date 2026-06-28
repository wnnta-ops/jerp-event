<?php

declare(strict_types=1);

namespace App\Http\Requests\Master\Unit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('master.unit.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'project_id'    => ['required', 'integer', 'exists:projects,id'],
            'cluster_id'    => ['nullable', 'integer', 'exists:clusters,id'],
            'block_id'      => ['nullable', 'integer', 'exists:blocks,id'],
            'unit_type_id'  => ['nullable', 'integer', 'exists:unit_types,id'],
            'unit_code'     => ['required', 'string', 'max:32', Rule::unique('units', 'unit_code')->where('project_id', $this->integer('project_id'))],
            'address'       => ['nullable', 'string', 'max:255'],
            'base_price'    => ['required', 'numeric', 'min:0'],
            'current_price' => ['nullable', 'numeric', 'min:0'],
            'status'        => ['nullable', Rule::in(['available', 'hold', 'booked', 'sold', 'cancelled', 'maintenance'])],
            'attributes'    => ['nullable', 'array'],
        ];
    }
}
