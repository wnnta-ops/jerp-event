<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SSO consumer: menerima token bertanda-tangan dari J-ERP (FO), memverifikasi
 * HMAC + kedaluwarsa, lalu login-kan user BO/Bankloan yang cocok (by email).
 */
class SsoController extends Controller
{
    public function consume(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $secret = (string) config('sso.secret', '');
        abort_if($secret === '' || $token === '', 403, 'SSO tidak terkonfigurasi.');

        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
        $expected = hash_hmac('sha256', $payload, $secret);
        abort_unless(hash_equals($expected, $sig), 403, 'Token SSO tidak sah.');

        $data = json_decode(base64_decode($payload), true);
        abort_unless(is_array($data), 403, 'Token SSO rusak.');
        abort_if((int) ($data['exp'] ?? 0) < now()->timestamp, 403, 'Token SSO kedaluwarsa.');

        $email = (string) ($data['email'] ?? '');
        // Peta email FO→BO bila berbeda (lihat config/sso.php).
        $email = (string) (config('sso.email_map')[$email] ?? $email);
        $user = User::where('email', $email)->first();
        abort_unless($user, 403, "User '{$email}' tidak ada di BO. Samakan email user FO ↔ BO.");

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
