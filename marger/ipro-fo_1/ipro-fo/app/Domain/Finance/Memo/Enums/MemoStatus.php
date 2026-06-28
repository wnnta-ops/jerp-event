<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Enums;

enum MemoStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Menunggu Approval',
            self::Approved        => 'Disetujui',
            self::Rejected        => 'Ditolak',
            self::Cancelled       => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft           => 'neutral',
            self::PendingApproval => 'warning',
            self::Approved        => 'success',
            self::Rejected        => 'danger',
            self::Cancelled       => 'danger',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Approved;
    }
}
