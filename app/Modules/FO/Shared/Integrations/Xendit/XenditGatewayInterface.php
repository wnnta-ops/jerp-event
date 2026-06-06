<?php

namespace App\Modules\FO\Shared\Integrations\Xendit;

interface XenditGatewayInterface
{
    /**
     * Create a UTJ payment invoice.
     */
    public function createInvoice(string $externalId, float $amount, string $payerEmail, string $description): XenditInvoiceResult;

    /**
     * Force-expire an unpaid invoice.
     */
    public function expireInvoice(string $invoiceId): XenditInvoiceResult;

    /**
     * Refund a paid invoice (returns the refund reference id).
     */
    public function refund(string $invoiceId, float $amount, string $reason): string;
}
