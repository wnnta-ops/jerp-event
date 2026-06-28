<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Foundation\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve tenant berdasarkan urutan:
 *   1. Header `X-Tenant: {slug}`
 *   2. Subdomain (`mup.ipro-fo.com` -> `mup`)
 *   3. Authenticated user's tenant_id
 *   4. Fallback: tenant dengan code `MUP`
 *
 * Hasilnya di-set ke container singleton `tenant.id`.
 */
final class SetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId !== null) {
            app()->instance('tenant.id', $tenantId);
        }

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?int
    {
        // 1. Explicit header (case-insensitive terhadap code tenant).
        if ($code = $request->header('X-Tenant')) {
            if ($id = $this->tenantIdByCode((string) $code)) {
                return $id;
            }
        }

        // 2. User yang login → tenant miliknya.
        if ($user = $request->user()) {
            return $user->tenant_id;
        }

        // 3. Subdomain — HANYA dipakai bila benar-benar cocok dengan code tenant.
        //    Host publik multi-label (mis. *.ts.net, *.trycloudflare.com) TIDAK
        //    boleh memaksa null; biarkan jatuh ke fallback default di bawah.
        $hostParts = explode('.', $request->getHost());
        if (count($hostParts) > 2) {
            if ($id = $this->tenantIdByCode($hostParts[0])) {
                return $id;
            }
        }

        // 4. Fallback: tenant default (MUP), atau tenant pertama bila MUP tak ada.
        return $this->tenantIdByCode('MUP')
            ?? Tenant::query()->orderBy('id')->value('id');
    }

    /** Cari id tenant berdasarkan code (case-insensitive). */
    private function tenantIdByCode(string $code): ?int
    {
        $id = Tenant::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->value('id');

        return $id !== null ? (int) $id : null;
    }
}
