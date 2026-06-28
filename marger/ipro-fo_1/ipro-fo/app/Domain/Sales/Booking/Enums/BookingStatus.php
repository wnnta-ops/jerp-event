<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Enums;

use App\Exceptions\InvalidStateTransitionException;

enum BookingStatus: string
{
    case Draft     = 'draft';
    case Nup       = 'nup';
    case PilihUnit = 'pilih_unit';
    case Ppjb      = 'ppjb';
    case Active    = 'active';
    case Lunas     = 'lunas';
    case Closed    = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Nup       => 'NUP',
            self::PilihUnit => 'Pilih Unit',
            self::Ppjb      => 'PPJB',
            self::Active    => 'Aktif',
            self::Lunas     => 'Lunas',
            self::Closed    => 'Closed',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft     => 'neutral',
            self::Nup       => 'info',
            self::PilihUnit => 'info',
            self::Ppjb      => 'warning',
            self::Active    => 'success',
            self::Lunas     => 'success',
            self::Closed    => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Nup, self::PilihUnit, self::Active, self::Cancelled],
            self::Nup       => [self::PilihUnit, self::Cancelled],
            self::PilihUnit => [self::Ppjb, self::Active, self::Cancelled],
            self::Ppjb      => [self::Active, self::Lunas, self::Cancelled],
            self::Active    => [self::Ppjb, self::Lunas, self::Cancelled],
            self::Lunas     => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function assertCanTransitionTo(self $next): void
    {
        if (! $this->canTransitionTo($next)) {
            throw new InvalidStateTransitionException($this->value, $next->value, 'Booking');
        }
    }
}
