<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Actions;

use App\Domain\Sales\Contract\Enums\PpjbStatus;
use App\Domain\Sales\Contract\Models\Contract;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * Transition PPJB: Proses Tanda Tangan → Selesai.
 *
 * Set ppjb_signed_at = today.
 */
final class CompletePpjb
{
    public function execute(Contract $contract, ?string $signedAt = null): Contract
    {
        $current = $contract->ppjb_status;
        $next    = PpjbStatus::Selesai;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStateTransitionException(
                "PPJB status {$current->value} tidak bisa transit ke {$next->value}.",
            );
        }

        return DB::transaction(function () use ($contract, $next, $signedAt): Contract {
            $contract->ppjb_status     = $next;
            $contract->ppjb_signed_at  = $signedAt ?? now()->toDateString();
            $contract->signed_at       = $contract->signed_at ?? $contract->ppjb_signed_at;
            $contract->save();

            return $contract;
        });
    }
}
