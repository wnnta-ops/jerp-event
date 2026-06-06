<?php

namespace App\Modules\FO\Launching\Controllers;

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Xendit invoice webhook. Xendit POSTs invoice status changes with an
 * `x-callback-token` header that must match config('services.xendit.webhook_token').
 */
class XenditWebhookController
{
    public function __invoke(Request $request, VerifikasiNupService $service): JsonResponse
    {
        $expected = (string) config('services.xendit.webhook_token');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('x-callback-token'))) {
            return response()->json(['message' => 'Invalid callback token'], 401);
        }

        $externalId = (string) $request->input('external_id');
        $status = strtoupper((string) $request->input('status'));

        // External id pembayaran = kolom khusus Xendit (bukan no_nup, yang baru
        // terbit setelah approval). Fallback ke no_nup untuk invoice lama.
        $reg = NupRegistration::where('xendit_external_id', $externalId)
            ->orWhere('no_nup', $externalId)
            ->first();
        if ($reg === null) {
            return response()->json(['message' => 'NUP tidak ditemukan'], 404);
        }

        if (in_array($status, ['PAID', 'SETTLED'], true)) {
            $service->markPaid($reg);
        } elseif ($status === 'EXPIRED') {
            $reg->update(['xendit_status' => 'EXPIRED']);
        }

        return response()->json(['message' => 'ok']);
    }
}
