<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Finance\Payment\Services\XenditService;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Sales\Nup\Models\NupRegistration;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pendaftaran NUP versi form lengkap (mirror nup.javaresidence.com):
 *  1. Buat Customer dari data pemesan.
 *  2. Buat NupRegistration (+ simpan form_data lengkap).
 *  3. Buat invoice Xendit untuk nominal NUP → simpan URL pembayaran.
 *
 * Invoice dibuat DI LUAR transaksi customer/NUP supaya kegagalan gateway tidak
 * meng-orphan/rollback pendaftaran (link bayar bisa dibuat ulang dari halaman NUP).
 */
final class RegisterNupPublic
{
    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly RegisterNup $registerNup,
        private readonly XenditService $xendit,
    ) {}

    /**
     * @param array<string,mixed> $customerData  Data customer (mapped ke kolom customers).
     * @param array<string,mixed> $formData       Data form NUP tambahan (preferensi unit, pasangan, sales, dll).
     * @param array{success?:?string,failure?:?string,simulate?:?string} $urls
     */
    public function execute(array $customerData, int $schemeId, array $formData, array $urls = [], ?int $salesPersonId = null, ?int $agentId = null): NupRegistration
    {
        $reg = DB::transaction(function () use ($customerData, $schemeId, $formData, $salesPersonId, $agentId): NupRegistration {
            $customer = $this->createCustomer->execute($customerData);

            $reg = $this->registerNup->execute([
                'nup_scheme_id'   => $schemeId,
                'customer_id'     => $customer->id,
                'sales_person_id' => $salesPersonId,
                'agent_id'        => $agentId,
            ]);

            $reg->update(['form_data' => $formData, 'payment_status' => 'unpaid']);

            return $reg->fresh(['scheme', 'customer']);
        });

        // ── Buat invoice (di luar transaksi) ──
        $this->generateInvoice($reg, $urls);

        return $reg->fresh(['scheme', 'customer']);
    }

    /**
     * Buat / buat-ulang invoice pembayaran untuk sebuah NUP.
     *
     * @param array{success?:?string,failure?:?string,simulate?:?string} $urls
     */
    public function generateInvoice(NupRegistration $reg, array $urls = []): NupRegistration
    {
        try {
            $scheme   = $reg->scheme;
            $customer = $reg->customer;

            $invoice = $this->xendit->createInvoice(
                externalId: $reg->nup_number.'-'.now()->format('His'),
                amount: (float) $scheme->nup_amount,
                opts: [
                    'email'        => $customer?->email,
                    'phone'        => $customer?->phone,
                    'name'         => $customer?->full_name,
                    'description'  => "Pembayaran NUP {$scheme->name} — {$reg->nup_number}",
                    'success_url'  => $urls['success'] ?? null,
                    'failure_url'  => $urls['failure'] ?? null,
                    'simulate_url' => $urls['simulate'] ?? null,
                ],
            );

            $reg->update([
                'payment_provider'    => $invoice['provider'],
                'payment_ref'         => $invoice['id'],
                'payment_url'         => $invoice['invoice_url'],
                'payment_external_id' => $reg->nup_number,
                // Tetap 'unpaid' hingga benar-benar dibayar (webhook Xendit → 'paid').
                'payment_status'      => 'unpaid',
            ]);
        } catch (Throwable $e) {
            report($e);
            // Biarkan payment_status tetap 'unpaid' — link bisa dibuat ulang nanti.
        }

        return $reg->fresh();
    }
}
