<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $key = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, config('domain.auth.max_failed_attempts'))) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik."],
            ])->status(429);
        }

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, config('domain.auth.lockout_minutes') * 60);

            if ($user) {
                $user->increment('failed_login_count');
                if ($user->failed_login_count >= config('domain.auth.max_failed_attempts')) {
                    $user->update(['is_blocked' => true, 'blocked_until' => now()->addMinutes(15)]);
                }
            }

            return response()->json([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Email atau password salah.'],
            ], 401);
        }

        if ($user->is_blocked || ! $user->is_active) {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_BLOCKED', 'message' => 'Akun nonaktif atau diblokir. Hubungi admin.'],
            ], 403);
        }

        if ($user->mfaSecret?->confirmed_at !== null) {
            $tempToken = $user->createToken('mfa-challenge', ['mfa:verify'], now()->addMinutes(5))->plainTextToken;

            return response()->json([
                'error' => [
                    'code'    => 'MFA_REQUIRED',
                    'message' => 'Lanjutkan verifikasi MFA.',
                    'details' => ['temp_token' => $tempToken],
                ],
            ], 401);
        }

        RateLimiter::clear($key);
        $user->update([
            'failed_login_count' => 0,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        $token = $user->createToken($data['device_name'] ?? 'web', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'data' => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ],
                'token'       => $token,
                'expires_at'  => now()->addDays(7)->toIso8601String(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'tenant_id'   => $user->tenant_id,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }
}
