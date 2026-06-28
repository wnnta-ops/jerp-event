<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Actions;

use App\Domain\BankLoan\Models\KprAkad;
use App\Domain\BankLoan\Models\KprApplication;
use App\Exceptions\BusinessRuleException;

final class ScheduleAkad
{
    public function execute(KprApplication $app, array $data): KprAkad
    {
        if ($app->status->value !== 'sp3k') {
            throw new BusinessRuleException(
                'Akad hanya dapat dijadwalkan setelah SP3K diterbitkan.',
                errorCode: 'AKAD_REQUIRES_SP3K',
            );
        }

        return KprAkad::updateOrCreate(
            ['kpr_application_id' => $app->id],
            [
                'akad_at' => $data['akad_at'],
                'place'   => $data['place']  ?? null,
                'notary'  => $data['notary'] ?? null,
                'status'  => 'scheduled',
            ],
        );
    }
}
