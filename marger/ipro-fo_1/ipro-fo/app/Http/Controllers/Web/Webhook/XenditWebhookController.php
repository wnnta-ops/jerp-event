<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Webhook;

use App\Domain\Finance\Payment\Services\XenditService;
use App\Domain\Sales\Nup\Actions\MarkNupPaid;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook callback Xendit (Invoice paid/expired).
 * Endpoint publik (tanpa auth/CSRF) — diverifikasi via header x-callback-token.
 *
 * Daftarkan URL ini di dashboard Xendit:
 *   https://<domain-anda>/webhooks/xendit
 */
class XenditWebhookController extends Controller
{
    public function handle(Request $request, XenditService $xendit, MarkNupPaid $markPaid): JsonResponse
    {
        // Verifikasi token (kecuali payload uji bertanda simulation).
        $token = $request->header('x-callback-token');
        if (! $xendit->verifyWebhookToken($token)) {
            Log::warning('Xendit webhook ditolak: token tidak valid.');
            return response()->json(['message' => 'invalid token'], 401);
        }

        $status     = strtoupper((string) $request->input('status', ''));
        $invoiceId  = (string) $request->input('id', '');
        $externalId = (string) $request->input('external_id', '');
        $amount     = $request->input('paid_amount') ?? $request->input('amount');

        $reg = NupRegistration::query()
            ->when($invoiceId !== '', fn ($q) => $q->where('payment_ref', $invoiceId))
            ->when($invoiceId === '' && $externalId !== '', fn ($q) => $q->where('payment_external_id', $externalId))
            ->first();

        if (! $reg) {
            Log::info('Xendit webhook: NUP tidak ditemukan', compact('invoiceId', 'externalId'));
            return response()->json(['message' => 'not found'], 200); // 200 supaya Xendit tidak retry terus
        }

        if (in_array($status, ['PAID', 'SETTLED'], true)) {
            $markPaid->execute($reg, 'xendit', $invoiceId ?: $externalId, $amount !== null ? (float) $amount : null);
        } elseif (in_array($status, ['EXPIRED', 'FAILED'], true)) {
            $reg->update(['payment_status' => strtolower($status)]);
        }

        return response()->json(['message' => 'ok'], 200);
    }
}
