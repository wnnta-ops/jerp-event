<?php

declare(strict_types=1);

namespace App\Domain\Legal\Ppjb\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Legal\Ppjb\Models\PpjbDocument;
use App\Domain\Sales\Contract\Models\Contract;
use App\Exceptions\BusinessRuleException;

final class IssuePpjb
{
    public function __construct(private readonly NumeratorService $numerator) {}

    public function execute(Contract $contract): PpjbDocument
    {
        if ($contract->status !== 'signed') {
            throw new BusinessRuleException('Kontrak harus signed dulu sebelum issue PPJB.', errorCode: 'CONTRACT_NOT_SIGNED');
        }

        return PpjbDocument::updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'ppjb_number' => $this->numerator->next('PPJB'),
                'signed_at'   => $contract->signed_at ?? now()->toDateString(),
                'status'      => 'active',
            ],
        );
    }
}
