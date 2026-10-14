<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Ganti password oleh penggunanya sendiri (menu nama pengguna di Dashboard).
 *
 * Hanya password — email sengaja tidak bisa diubah di sini, karena email adalah pengikat
 * akun ke BO (SSO FO→BO mencocokkan lewat email). Password awal biasanya diisi admin lewat
 * Import Excel Pengguna; halaman ini tempat pengguna menggantinya.
 */
class AkunPasswordController extends Controller
{
    public function edit(): View
    {
        return view('akun.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password_lama' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'password_lama.current_password' => 'Password lama salah.',
            'password.confirmed' => 'Konfirmasi password baru tidak sama.',
            'password.min' => 'Password baru minimal 8 karakter.',
        ]);

        // Cast 'hashed' di model User yang meng-hash-nya.
        $request->user()->update(['password' => $data['password']]);

        return redirect()->route('akun.password')->with('status', 'Password berhasil diganti.');
    }
}
