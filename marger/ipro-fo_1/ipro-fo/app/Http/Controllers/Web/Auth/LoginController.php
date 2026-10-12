<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): RedirectResponse|Response
    {
        // Akses BO hanya via login J-ERP (1 pintu) → alihkan ke FO_URL/login.
        if (config('sso.fo_login_only')) {
            return redirect()->away(config('sso.fo_url').'/login');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        // Login langsung BO dimatikan saat mode 1-pintu — paksa lewat J-ERP.
        if (config('sso.fo_login_only')) {
            return redirect()->away(config('sso.fo_url').'/login');
        }

        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $data['remember'] ?? false)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip(), 'failed_login_count' => 0]);

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // 1-pintu: setelah logout BO, kembali ke login J-ERP (bukan login BO).
        //
        // Tombol Keluar mengirim router.post Inertia (XHR). XHR tidak bisa mengikuti 302 ke
        // domain lain — diblokir CORS, dan di layar tombolnya tampak tidak berbuat apa-apa,
        // padahal sesinya sudah terhapus. Inertia::location menyuruh browser pindah halaman penuh.
        if (config('sso.fo_login_only')) {
            $tujuan = config('sso.fo_url').'/login';

            return $request->header('X-Inertia') ? Inertia::location($tujuan) : redirect()->away($tujuan);
        }

        return redirect('/login');
    }
}
