<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Nup\Models\NupPayment;
use App\Domain\Sales\Nup\Models\NupRegistration;
use Illuminate\Support\Facades\DB;

/**
 * Tandai NUP lunas (dipanggil dari webhook Xendit atau tombol simulasi).
 * Idempoten — aman dipanggil ulang (mis. webhook retry).
 */
final class MarkNupPaid
{
    public function execute(NupRegistration $reg, string $provider = 'xendit', ?string $reference = null, ?float $amount = null): NupRegistration
    {
        if ($reg->payment_status === 'paid') {
            return $reg; // sudah lunas — idempotent
        }

        return DB::transaction(function () use ($reg, $provider, $reference, $amount): NupRegistration {
            NupPayment::create([
                'nup_registration_id' => $reg->id,
                'amount'              => $amount ?? (float) $reg->scheme->nup_amount,
                'paid_at'             => now()->toDateString(),
                'payment_method'      => 'transfer', // gateway online → transfer
                'reference_no'        => $reference,
                // Webhook/CLI tidak punya user login → fallback ke user sistem (mis. verifier/SuperAdmin)
                // agar tidak melanggar NOT NULL pada received_by.
                'received_by'         => auth()->id()
                    ?? $reg->verified_by
                    ?? \App\Models\User::query()->orderBy('id')->value('id'),
            ]);

            $reg->update([
                'status'           => 'paid',
                'payment_status'   => 'paid',
                'payment_provider' => $provider,
            ]);

            return $reg->fresh();
        });
    }
}
