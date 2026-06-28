<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use Illuminate\Support\Facades\DB;

final class CreateKprApplication
{
    public function __construct(private readonly NumeratorService $numerator) {}

    public function execute(array $data): KprApplication
    {
        return DB::transaction(function () use ($data): KprApplication {
            return KprApplication::create([
                ...$data,
                'application_number' => $this->numerator->next('KPR'),
                'submitted_at'       => now()->toDateString(),
                'status'             => KprStatus::Draft,
                'handled_by'         => auth()->id(),
            ]);
        });
    }
}
