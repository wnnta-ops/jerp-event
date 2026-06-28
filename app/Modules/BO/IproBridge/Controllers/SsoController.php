<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * SSO token-handoff: J-ERP (FO) sebagai penerbit token. User yang sudah login di
 * J-ERP membuka BO/Bankloan ipro-fo lewat token bertanda-tangan HMAC (shared
 * secret). ipro-fo memverifikasi + login-kan user yg cocok (by email).
 */
class SsoController
{
    public function toBo(): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $secret = (string) config('ipro.sso_secret');
        abort_if($secret === '', 500, 'SSO_SHARED_SECRET belum diset.');

        $payload = base64_encode((string) json_encode([
            'email' => $user->email,
            'name'  => $user->name,
            'exp'   => now()->addSeconds(60)->timestamp,
        ]));
        $sig = hash_hmac('sha256', $payload, $secret);
        $token = $payload . '.' . $sig;

        return redirect(config('ipro.bo_url') . '/sso/consume?token=' . urlencode($token));
    }
}
