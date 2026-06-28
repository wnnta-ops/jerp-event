<?php

declare(strict_types=1);

namespace App\Http\Requests\Master\Unit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteplanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('master.unit.siteplan.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'x'        => ['required', 'numeric'],
            'y'        => ['required', 'numeric'],
            'width'    => ['nullable', 'numeric', 'min:0'],
            'height'   => ['nullable', 'numeric', 'min:0'],
            'rotation' => ['nullable', 'numeric', 'between:-360,360'],
            'svg_path' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
