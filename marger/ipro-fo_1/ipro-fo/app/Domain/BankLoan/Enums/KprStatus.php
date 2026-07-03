<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Enums;

use App\Exceptions\InvalidStateTransitionException;

enum KprStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case InReview    = 'in_review';
    case Sp3kIssued  = 'sp3k';
    case Si          = 'si';
    case AkadDone    = 'akad';
    case Disbursed   = 'disbursed';
    case Active      = 'active';
    case Rejected    = 'rejected';
    case Cancelled   = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Draft',
            self::Submitted   => 'Submitted',
            self::InReview    => 'In Review',
            self::Sp3kIssued  => 'SP3K Issued',
            self::Si          => 'SI',
            self::AkadDone    => 'Akad Done',
            self::Disbursed   => 'Pencairan',
            self::Active      => 'Lunas',
            self::Rejected    => 'Rejected',
            self::Cancelled   => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft       => 'neutral',
            self::Submitted, self::InReview => 'warning',
            self::Sp3kIssued, self::Si, self::AkadDone => 'info',
            self::Disbursed, self::Active => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted, self::Cancelled],
            // Submitted boleh langsung ke SP3K (tahap In Review opsional, tak ada UI-nya).
            self::Submitted   => [self::InReview, self::Sp3kIssued, self::Rejected, self::Cancelled],
            self::InReview    => [self::Sp3kIssued, self::Rejected, self::Cancelled],
            self::Sp3kIssued  => [self::Si, self::Rejected, self::Cancelled],
            self::Si          => [self::AkadDone, self::Rejected, self::Cancelled],
            self::AkadDone    => [self::Disbursed, self::Cancelled],
            self::Disbursed   => [self::Active],
            self::Active, self::Rejected, self::Cancelled => [],
        };
    }

    public function assertCanTransitionTo(self $next): void
    {
        if (! in_array($next, $this->allowedTransitions(), true)) {
            throw new InvalidStateTransitionException($this->value, $next->value, 'KPR Application');
        }
    }
}
