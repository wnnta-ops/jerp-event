<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Sales\Contract\Enums\PpjbStatus;
use App\Domain\Sales\Contract\Models\Contract;
use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * Transition PPJB: Belum di Proses → Print.
 *
 * Auto-generate ppjb_system_number (kalau belum ada) + set tanggal_cetak.
 */
final class PrintPpjb
{
    public function __construct(
        private readonly NumeratorService $numerator,
    ) {}

    public function execute(Contract $contract): Contract
    {
        $current = $contract->ppjb_status;
        $next    = PpjbStatus::Print;

        if (! $current->canTransitionTo($next)) {
            throw new InvalidStateTransitionException(
                "PPJB status {$current->value} tidak bisa transit ke {$next->value}.",
            );
        }

        return DB::transaction(function () use ($contract, $next): Contract {
            // Auto-gen system number kalau kosong
            if (empty($contract->ppjb_system_number)) {
                $contract->ppjb_system_number = $this->numerator->next('PPJB');
            }

            $contract->ppjb_status      = $next;
            $contract->ppjb_printed_at  = now()->toDateString();
            $contract->ppjb_date        = $contract->ppjb_date ?? now()->toDateString();
            $contract->save();

            return $contract;
        });
    }
}
