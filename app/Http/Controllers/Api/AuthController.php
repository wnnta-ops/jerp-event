<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Modules\FO\Approval\Models\PushSubscription;
use App\Modules\FO\Approval\Services\BoChainApproverCheck;
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
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['Akun Anda dinonaktifkan.']]);
        }
        if (! $this->isApprover($user)) {
            throw ValidationException::withMessages(['email' => ['Akun Anda bukan approver.']]);
        }

        // Token di-scope hanya untuk aksi approval (bukan ability '*').
        $token = $user->createToken($data['device'] ?? 'mobile-approver', ['approval:act'])->plainTextToken;

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
        $token = $request->user()->currentAccessToken();
        // Perangkat ini berhenti menerima notifikasi push akun ini.
        PushSubscription::hapusUntukToken($token->getKey());
        $token->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * User boleh masuk app approver bila bisa bertindak atas minimal satu jenis approval FO,
     * atau punya tahap di Approval Chain BO Approval Inbox.
     */
    private function isApprover(User $user): bool
    {
        if ($user->hasRole('super_admin') || $user->hasAnyRole((array) config('approval.final_roles', []))) {
            return true;
        }

        $perms = array_values((array) config('approval.jenis_permission', []));
        $perms[] = (string) config('approval.fallback_permission');

        foreach (array_unique(array_filter($perms)) as $perm) {
            if ($user->can($perm)) {
                return true;
            }
        }

        return app(BoChainApproverCheck::class)->isApprover((string) $user->email);
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
