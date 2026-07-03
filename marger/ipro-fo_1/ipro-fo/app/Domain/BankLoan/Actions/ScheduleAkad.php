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
        if ($app->status->value !== 'akad') {
            throw new BusinessRuleException(
                'Akad hanya dapat dicatat pada tahap Akad (setelah Surat Instruksi).',
                errorCode: 'AKAD_REQUIRES_STAGE_AKAD',
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
