<?php

declare(strict_types=1);

namespace App\Domain\Finance\Payment\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi pembayaran Xendit (Invoice API).
 *
 * Mode:
 *  - REAL: aktif bila XENDIT_SECRET_KEY terisi → memanggil https://api.xendit.co/v2/invoices
 *  - SIMULASI: bila key kosong → kembalikan invoice tiruan (status PENDING) supaya
 *    alur dapat diuji tanpa key asli. Pembayaran ditandai lunas lewat tombol simulasi.
 *
 * Dok: https://developers.xendit.co/api-reference/#create-invoice
 */
final class XenditService
{
    public function enabled(): bool
    {
        return filled(config('services.xendit.secret_key'));
    }

    /**
     * Buat invoice. Mengembalikan info ringkas.
     *
     * @param array{email?:?string,phone?:?string,name?:?string,description?:?string,success_url?:?string,failure_url?:?string,simulate_url?:?string} $opts
     * @return array{id:string,invoice_url:?string,status:string,provider:string}
     */
    public function createInvoice(string $externalId, float $amount, array $opts = []): array
    {
        if (! $this->enabled()) {
            // ── MODE SIMULASI ──
            return [
                'id'          => 'SIM-'.$externalId,
                'invoice_url' => $opts['simulate_url'] ?? null,
                'status'      => 'PENDING',
                'provider'    => 'simulation',
            ];
        }

        $payload = array_filter([
            'external_id'          => $externalId,
            'amount'               => round($amount),
            'currency'             => 'IDR',
            'description'          => $opts['description'] ?? 'Pembayaran NUP',
            'payer_email'          => $opts['email'] ?? null,
            'success_redirect_url' => $opts['success_url'] ?? null,
            'failure_redirect_url' => $opts['failure_url'] ?? null,
            'invoice_duration'     => (int) config('services.xendit.invoice_duration', 86400),
            'customer'             => array_filter([
                'given_names'   => $opts['name'] ?? null,
                'email'         => $opts['email'] ?? null,
                'mobile_number' => $opts['phone'] ?? null,
            ]),
        ], static fn ($v) => $v !== null && $v !== []);

        $resp = Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->post(rtrim((string) config('services.xendit.base_url'), '/').'/v2/invoices', $payload);

        if ($resp->failed()) {
            Log::error('Xendit invoice gagal', ['status' => $resp->status(), 'body' => $resp->json()]);
            throw new BusinessRuleException(
                'Gagal membuat invoice Xendit (HTTP '.$resp->status().'). Cek XENDIT_SECRET_KEY.',
                errorCode: 'XENDIT_INVOICE_FAILED',
                details: ['response' => $resp->json()],
            );
        }

        $d = $resp->json();

        return [
            'id'          => (string) ($d['id'] ?? $externalId),
            'invoice_url' => $d['invoice_url'] ?? null,
            'status'      => (string) ($d['status'] ?? 'PENDING'),
            'provider'    => 'xendit',
        ];
    }

    /**
     * Verifikasi callback token Xendit (header x-callback-token).
     */
    public function verifyWebhookToken(?string $token): bool
    {
        $expected = config('services.xendit.webhook_token');
        if (blank($expected)) {
            return false;
        }

        return is_string($token) && hash_equals((string) $expected, $token);
    }
}
