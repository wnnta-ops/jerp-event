<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Auth token API untuk aplikasi mobile approver (Sanctum personal access token).
 * login → token; gunakan header Authorization: Bearer <token> untuk endpoint lain.
 */
class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Email atau password salah.']]);
        }

        $token = $user->createToken($data['device'] ?? 'mobile-approver')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function profile(User $user): array
    {
        $role = $user->getRoleNames()->first();
        $name = $user->name ?: $user->full_name ?: $user->email;

        return [
            'id' => $user->id,
            'name' => $name,
            'role' => $role ? ucwords(str_replace('_', ' ', $role)) : 'Approver',
            'roleKey' => $role,
            'init' => strtoupper(mb_substr($name, 0, 1)),
        ];
    }
}
