<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Enums;

enum TtsStatus: string
{
    case Pending   = 'pending';
    case Verified  = 'verified';
    case Posted    = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Verified  => 'Verified',
            self::Posted    => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending   => 'warning',
            self::Verified  => 'info',
            self::Posted    => 'success',
            self::Cancelled => 'danger',
        };
    }
}
