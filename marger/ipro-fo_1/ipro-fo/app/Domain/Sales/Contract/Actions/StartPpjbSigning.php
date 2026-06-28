<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Actions;

use App\Domain\Sales\Contract\Enums\PpjbStatus;
use App\Domain\Sales\Contract\Models\Contract;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * Transition PPJB: Print → Proses Tanda Tangan.
 *
 * Gate: dokumen wajib (KK/KTP/NPWP) harus is_present = true.
 */
final class StartPpjbSigning
{
    public function execute(Contract $contract): Contract
    {
        $current = $contract->ppjb_status;
        $next    = PpjbStatus::ProsesTandaTangan;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStateTransitionException(
                "PPJB status {$current->value} tidak bisa transit ke {$next->value}.",
            );
        }

        // Document compliance gate
        $compliance = $contract->checkDocumentCompliance('ppjb_sign');
        if (! $compliance['complete']) {
            $missing = collect($compliance['missing'])->map(fn ($t) => $t->label())->implode(', ');
            throw new \DomainException(
                "Dokumen wajib belum lengkap: {$missing}. Lengkapi dulu sebelum proses tanda tangan.",
            );
        }

        return DB::transaction(function () use ($contract, $next): Contract {
            $contract->ppjb_status = $next;
            $contract->save();
            return $contract;
        });
    }
}
