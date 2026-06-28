<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set audit context (IP, UA, Request ID) ke container singleton agar
 * AuditObserver dapat memasangnya ke setiap audit_logs row.
 */
final class AuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('audit.context', [
            'ip'         => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-ID', (string) str()->uuid()),
        ]);

        return $next($request);
    }
}
