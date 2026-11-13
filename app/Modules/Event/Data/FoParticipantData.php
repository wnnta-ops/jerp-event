<?php

namespace App\Modules\Event\Data;

use Carbon\CarbonInterface;

/**
 * Potret satu NUP FO yang dibaca modul Event. Dibuat hanya oleh FoNupLookup —
 * modul Event tak pernah memegang model FO secara langsung.
 */
final class FoParticipantData
{
    public function __construct(
        public readonly int $nup_registration_id,
        public readonly int $nup_event_id,
        public readonly ?string $no_nup,
        public readonly ?string $customer_code,
        public readonly ?int $customer_id,
        public readonly ?string $name,
        public readonly ?string $phone,
        public readonly ?string $verif_status,
        public readonly ?string $status,
        public readonly bool $is_hold,
        public readonly ?CarbonInterface $tgl_check_in,
    ) {}

    public function isApproved(): bool
    {
        return $this->verif_status === 'APPROVED';
    }

    /** Sudah aktivasi di pos 1 (FO): tgl_check_in terisi & status CHECK_IN / PILIH_UNIT. */
    public function isCheckedIn(): bool
    {
        return $this->tgl_check_in !== null && in_array($this->status, ['CHECK_IN', 'PILIH_UNIT'], true);
    }

    /** Boleh di-scan di pos setelah pos 1. */
    public function isEligible(): bool
    {
        return $this->isApproved() && $this->isCheckedIn() && ! $this->is_hold;
    }

    /** Pesan penolakan (Indonesia) bila tidak eligible, null bila boleh. */
    public function rejectionReason(): ?string
    {
        return match (true) {
            ! $this->isApproved() => 'NUP belum disetujui',
            ! $this->isCheckedIn() => 'Belum aktivasi di pos 1',
            $this->is_hold => 'NUP sedang di-hold',
            default => null,
        };
    }
}
