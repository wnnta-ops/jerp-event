<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $requiredRoles = config('domain.auth.mfa_required_roles', []);
        $needsMfa = $user->hasAnyRole($requiredRoles) || $user->mfaSecret?->confirmed_at !== null;

        if ($needsMfa && ! $request->session()->get('mfa.verified', false)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => ['code' => 'MFA_REQUIRED', 'message' => 'Verifikasi MFA diperlukan.'],
                ], 401);
            }

            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
