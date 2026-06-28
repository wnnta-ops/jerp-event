<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use Illuminate\Support\Facades\DB;

final class CompleteAkad
{
    public function execute(KprApplication $app): void
    {
        $app->status->assertCanTransitionTo(KprStatus::AkadDone);

        DB::transaction(function () use ($app): void {
            $app->akad?->update(['status' => 'done']);
            $app->update(['status' => KprStatus::AkadDone]);
        });
    }
}
