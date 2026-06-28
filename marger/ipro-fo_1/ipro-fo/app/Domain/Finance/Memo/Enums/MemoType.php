<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Enums;

/**
 * Jenis memo.
 *
 *   Incoming   = memo penerimaan (legacy)
 *   Outgoing   = memo pengeluaran (legacy)
 *   FreeDp     = Free DP (DP 100% gratis dari developer)
 *   SubsidiDp  = Subsidi DP (DP partial gratis)
 *   Adjustment = penyesuaian / koreksi
 */
enum MemoType: string
{
    case Incoming   = 'incoming';
    case Outgoing   = 'outgoing';
    case FreeDp     = 'free_dp';
    case SubsidiDp  = 'subsidi_dp';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Incoming   => 'Memo Masuk',
            self::Outgoing   => 'Memo Keluar',
            self::FreeDp     => 'Free DP',
            self::SubsidiDp  => 'Subsidi DP',
            self::Adjustment => 'Adjustment',
        };
    }

    public function requiresApproval(): bool
    {
        return in_array($this, [self::FreeDp, self::SubsidiDp, self::Adjustment], true);
    }
}
