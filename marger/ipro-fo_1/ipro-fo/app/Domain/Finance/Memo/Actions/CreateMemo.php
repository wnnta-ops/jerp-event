<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Actions;

use App\Domain\Finance\Memo\Models\Memo;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use Illuminate\Support\Facades\DB;

final class CreateMemo
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /**
     * @param array<string, mixed> $data Validated payload (type, memo_date, amount, description, ...)
     */
    public function execute(array $data): Memo
    {
        return DB::transaction(function () use ($data): Memo {
            return Memo::create([
                ...$data,
                'memo_number' => $this->numerator->next('MEMO'),
                'created_by'  => auth()->id(),
                'status'      => $data['status'] ?? 'draft',
            ]);
        });
    }
}
