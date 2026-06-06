<?php

namespace App\Modules\FO\Shared\Integrations\Xendit;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Xendit API gateway (Invoices API). Uses HTTP Basic auth with the
 * secret key as username and an empty password.
 *
 * @see https://developers.xendit.co/api-reference/#create-invoice
 */
class XenditGateway implements XenditGatewayInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.xendit.co',
    ) {}

    public function createInvoice(string $externalId, float $amount, string $payerEmail, string $description): XenditInvoiceResult
    {
        $response = $this->client()->post($this->baseUrl.'/v2/invoices', [
            'external_id' => $externalId,
            'amount' => $amount,
            'payer_email' => $payerEmail ?: null,
            'description' => $description,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Xendit createInvoice gagal: '.$response->body());
        }

        return new XenditInvoiceResult(
            id: (string) $response->json('id'),
            invoiceUrl: (string) $response->json('invoice_url'),
            status: strtoupper((string) $response->json('status', 'PENDING')),
        );
    }

    public function expireInvoice(string $invoiceId): XenditInvoiceResult
    {
        $response = $this->client()->post($this->baseUrl.'/invoices/'.$invoiceId.'/expire!');

        if ($response->failed()) {
            throw new RuntimeException('Xendit expireInvoice gagal: '.$response->body());
        }

        return new XenditInvoiceResult(
            id: $invoiceId,
            invoiceUrl: (string) $response->json('invoice_url', ''),
            status: 'EXPIRED',
        );
    }

    public function refund(string $invoiceId, float $amount, string $reason): string
    {
        $response = $this->client()->post($this->baseUrl.'/refunds', [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Xendit refund gagal: '.$response->body());
        }

        return (string) $response->json('id');
    }

    private function client()
    {
        return Http::withBasicAuth($this->secretKey, '')->acceptJson();
    }
}
