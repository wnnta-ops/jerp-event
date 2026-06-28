<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance\Memo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('finance.memo.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'type'             => ['required', Rule::in(['incoming', 'outgoing'])],
            'memo_date'        => ['required', 'date'],
            'amount'           => ['required', 'numeric', 'min:1'],
            'description'      => ['required', 'string', 'min:5', 'max:1000'],
            'reference_type'   => ['nullable', 'string', 'max:64'],
            'reference_id'     => ['nullable', 'integer'],
            'bank_account_id'  => ['nullable', 'integer', 'exists:bank_accounts,id'],
        ];
    }
}
