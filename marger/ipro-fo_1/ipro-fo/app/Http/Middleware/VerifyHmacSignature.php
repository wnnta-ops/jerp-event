<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi HMAC signature untuk webhook ingress (bank VA, PrivyID, SMS DLR).
 * Header: `X-Signature: sha256={hex}`
 * Secret resolved dari config domain.webhook.{vendor}_secret berdasarkan path.
 */
final class VerifyHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->resolveSecret($request);
        if ($secret === null) {
            return response()->json(['error' => ['code' => 'UNSIGNED', 'message' => 'Webhook secret tidak ditemukan.']], 401);
        }

        $signature = (string) $request->header('X-Signature', '');
        $expected  = 'sha256='.hash_hmac('sha256', $request->getContent() ?: '', $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Signature tidak valid.']], 401);
        }

        return $next($request);
    }

    private function resolveSecret(Request $request): ?string
    {
        return match (true) {
            str_contains($request->path(), 'bank/va') => config('domain.webhook.bank_va_secret'),
            str_contains($request->path(), 'privyid') => config('domain.webhook.privyid_secret'),
            default                                    => null,
        };
    }
}
