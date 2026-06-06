<?php

namespace App\Modules\FO\Shared\Integrations\Xendit;

/**
 * Offline gateway used when no Xendit secret key is configured (local/testing).
 * Generates deterministic fake invoices without any network call.
 */
class NullXenditGateway implements XenditGatewayInterface
{
    public function createInvoice(string $externalId, float $amount, string $payerEmail, string $description): XenditInvoiceResult
    {
        $id = 'fake_'.$externalId;

        return new XenditInvoiceResult(
            id: $id,
            invoiceUrl: 'https://checkout.xendit.local/'.$id,
            status: 'PENDING',
        );
    }

    public function expireInvoice(string $invoiceId): XenditInvoiceResult
    {
        return new XenditInvoiceResult($invoiceId, '', 'EXPIRED');
    }

    public function refund(string $invoiceId, float $amount, string $reason): string
    {
        return 'fake_refund_'.$invoiceId;
    }
}
