<?php

declare(strict_types=1);

namespace App\Domain\Finance\Payment\Enums;

/**
 * Asal allocation ke payment schedule.
 *
 *   Tts              = dari TTS receipt (cash/transfer/debit/kredit/giro)
 *   Memo             = dari Memo (Free DP / Subsidi DP / adjustment)
 *   KprDisbursement  = dari KPR disbursement
 *   Manual           = entry manual oleh finance officer (rare)
 */
enum AllocationSource: string
{
    case Tts             = 'tts';
    case Memo            = 'memo';
    case KprDisbursement = 'kpr_disbursement';
    case Manual          = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Tts             => 'TTS',
            self::Memo            => 'Memo',
            self::KprDisbursement => 'Pencairan KPR',
            self::Manual          => 'Manual',
        };
    }

    /**
     * Map ke Eloquent model class untuk polymorphic resolution.
     */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::Tts             => \App\Domain\Finance\Tts\Models\Tts::class,
            self::Memo            => \App\Domain\Finance\Memo\Models\Memo::class,
            self::KprDisbursement => \App\Domain\BankLoan\Models\KprDisbursement::class,
            self::Manual          => null,
        };
    }
}
