<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Exceptions\BusinessRuleException;

/**
 * Set status verifikasi NUP (Approve / Pending / Reject) — tahap "Verifikasi
 * Data Customer" sebelum NUP masuk ke Daftar NUP utama.
 */
final class SetNupVerification
{
    private const ALLOWED = ['approved', 'pending', 'rejected'];

    public function execute(NupRegistration $reg, string $decision, ?string $note = null, ?int $userId = null): NupRegistration
    {
        $decision = strtolower(trim($decision));

        if (! in_array($decision, self::ALLOWED, true)) {
            throw new BusinessRuleException(
                "Keputusan verifikasi '{$decision}' tidak valid.",
                errorCode: 'NUP_VERIFICATION_INVALID',
            );
        }

        $reg->update([
            'verification_status' => $decision,
            'verified_at'         => $decision === 'pending' ? null : now(),
            'verified_by'         => $decision === 'pending' ? null : ($userId ?? auth()->id()),
            'verification_note'   => $note,
        ]);

        return $reg->fresh();
    }
}
