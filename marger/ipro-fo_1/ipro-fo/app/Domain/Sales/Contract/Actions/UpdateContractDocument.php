<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Actions;

use App\Domain\Sales\Contract\Enums\DocumentType;
use App\Domain\Sales\Contract\Models\Contract;
use App\Domain\Sales\Contract\Models\ContractDocument;

/**
 * Toggle document presence (Ada / Tidak Ada).
 */
final class UpdateContractDocument
{
    public function execute(Contract $contract, DocumentType $type, bool $present, ?string $notes = null): ContractDocument
    {
        $doc = ContractDocument::updateOrCreate(
            ['contract_id' => $contract->id, 'document_type' => $type->value],
            [
                'is_present'  => $present,
                'notes'       => $notes,
                'uploaded_by' => auth()->id(),
                'verified_at' => $present ? now() : null,
                'verified_by' => $present ? auth()->id() : null,
            ],
        );

        return $doc;
    }
}
