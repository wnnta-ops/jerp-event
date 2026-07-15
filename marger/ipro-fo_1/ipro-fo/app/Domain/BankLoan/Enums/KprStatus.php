<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Enums;

use App\Exceptions\InvalidStateTransitionException;

enum KprStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    // Tahap tambahan (antara Submitted → SP3K):
    case Wawancara   = 'wawancara';     // 3. Wawancara
    case InputBank   = 'input_bank';    // 4. Input Sistem Bank
    case Verifikasi  = 'verifikasi';    // 5. Proses Verifikasi (OTS + Wawancara Kedua)
    case Analis      = 'analis';        // 6. Proses Analis (+opsi banding)
    case Banding     = 'banding';       // 7. Proses Banding (opsional)
    case InReview    = 'in_review';     // legacy — tak dipakai di alur baru
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
            self::Wawancara   => 'Wawancara',
            self::InputBank   => 'Input Sistem Bank',
            self::Verifikasi  => 'Proses Verifikasi',
            self::Analis      => 'Proses Analis',
            self::Banding     => 'Proses Banding',
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
            self::Submitted, self::InReview,
            self::Wawancara, self::InputBank, self::Verifikasi, self::Analis, self::Banding => 'warning',
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
            // Submitted → tahap tambahan → SP3K. In Review legacy (masih boleh langsung SP3K).
            self::Submitted   => [self::Wawancara, self::InReview, self::Rejected, self::Cancelled],
            self::Wawancara   => [self::InputBank, self::Rejected, self::Cancelled],
            self::InputBank   => [self::Verifikasi, self::Rejected, self::Cancelled],
            self::Verifikasi  => [self::Analis, self::Rejected, self::Cancelled],
            // Analis → Banding (bila dipilih) ATAU langsung SP3K.
            self::Analis      => [self::Banding, self::Sp3kIssued, self::Rejected, self::Cancelled],
            self::Banding     => [self::Sp3kIssued, self::Rejected, self::Cancelled],
            // Legacy: boleh masuk alur baru (→ Wawancara) atau langsung SP3K (kompat lama).
            self::InReview    => [self::Wawancara, self::Sp3kIssued, self::Rejected, self::Cancelled],
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
