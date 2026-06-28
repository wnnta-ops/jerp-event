<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprSp3k;
use Illuminate\Support\Facades\DB;

final class RegisterSp3k
{
    public function execute(KprApplication $app, array $data): KprSp3k
    {
        $app->status->assertCanTransitionTo(KprStatus::Sp3kIssued);

        return DB::transaction(function () use ($app, $data): KprSp3k {
            $sp3k = KprSp3k::updateOrCreate(
                ['kpr_application_id' => $app->id],
                [
                    'sp3k_number'     => $data['sp3k_number'],
                    'sp3k_date'       => $data['sp3k_date'],
                    'approved_amount' => $data['approved_amount'],
                    'interest_pct'    => $data['interest_pct'] ?? null,
                    'tenor_month'    => $data['tenor_month']   ?? null,
                    'status'          => 'issued',
                    'expires_at'      => $data['expires_at']  ?? null,
                ],
            );

            $app->update([
                'status'          => KprStatus::Sp3kIssued,
                'plafon_approved' => $data['approved_amount'],
                'tenor_month'     => $data['tenor_month'] ?? $app->tenor_month,
                'reviewed_at'     => now()->toDateString(),
            ]);

            return $sp3k;
        });
    }
}
