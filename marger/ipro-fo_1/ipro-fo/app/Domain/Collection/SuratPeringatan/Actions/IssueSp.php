<?php

declare(strict_types=1);

namespace App\Domain\Collection\SuratPeringatan\Actions;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Collection\SuratPeringatan\Models\SuratPeringatan;
use App\Domain\Collection\Tunggakan\Models\TunggakanHeader;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class IssueSp
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly SubmitForApproval $submit,
    ) {}

    public function execute(TunggakanHeader $tunggakan, string $level, int $deadlineDays = 14): SuratPeringatan
    {
        if (! in_array($level, ['SP1', 'SP2', 'SP3'], true)) {
            throw new BusinessRuleException('Level tidak valid (SP1/SP2/SP3).', errorCode: 'INVALID_SP_LEVEL');
        }

        return DB::transaction(function () use ($tunggakan, $level, $deadlineDays): SuratPeringatan {
            $sp = SuratPeringatan::create([
                'sp_number'           => $this->numerator->next($level),
                'customer_id'         => $tunggakan->customer_id,
                'tunggakan_header_id' => $tunggakan->id,
                'level'               => $level,
                'issued_at'           => now()->toDateString(),
                'deadline_at'         => now()->addDays($deadlineDays)->toDateString(),
                'status'              => 'issued',
                'signed_by'           => auth()->id(),
            ]);

            // #118: SP harus disetujui finance sebelum dicetak (TTD finance).
            $this->submit->execute('surat_peringatan', $sp, [
                'sp_number'   => $sp->sp_number,
                'level'       => $level,
                'customer_id' => $tunggakan->customer_id,
                'amount'      => (float) $tunggakan->total_amount,
            ]);

            return $sp;
        });
    }
}
