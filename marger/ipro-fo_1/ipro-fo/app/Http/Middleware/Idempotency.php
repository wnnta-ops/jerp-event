<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency middleware untuk mutation API.
 * Wajib header `Idempotency-Key`. Replay request dengan key sama → return cached response.
 * Conflict (payload berbeda dengan key sama) → 422.
 */
final class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '') {
            return $next($request);   // tidak strict mandatory di dev — bisa diubah ke 400 jika prod
        }

        $payloadHash = hash('sha256', $request->getContent() ?: '');
        $route       = $request->getPathInfo();
        $userId      = $request->user()?->id;

        $existing = DB::table('idempotency_keys')->where('key', $key)->first();

        if ($existing) {
            if ($existing->payload_hash !== $payloadHash || $existing->route !== $route) {
                return response()->json([
                    'error' => [
                        'code'    => 'IDEMPOTENCY_VIOLATION',
                        'message' => 'Idempotency-Key sudah pernah dipakai dengan payload berbeda.',
                    ],
                ], 422);
            }
            if ($existing->response !== null) {
                return response($existing->response, (int) $existing->status_code)
                    ->header('Content-Type', 'application/json')
                    ->header('X-Idempotent-Replay', '1');
            }
        } else {
            DB::table('idempotency_keys')->insert([
                'key'          => $key,
                'user_id'      => $userId,
                'route'        => $route,
                'payload_hash' => $payloadHash,
                'created_at'   => now(),
                'expires_at'   => now()->addHours(24),
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        DB::table('idempotency_keys')
            ->where('key', $key)
            ->update([
                'response'    => $response->getContent(),
                'status_code' => $response->getStatusCode(),
            ]);

        return $response;
    }
}
