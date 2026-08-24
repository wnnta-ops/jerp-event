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
        $this->pastikanBoleh('bo');
        $token = $this->makeToken((string) config('ipro.sso_secret'));

        return redirect(config('ipro.bo_url') . '/sso/consume?token=' . urlencode($token));
    }

    /** Portal → app Legal (Next.js, path /legal) — verifikasi di /legal/api/sso. */
    public function toLegal(): RedirectResponse
    {
        $this->pastikanBoleh('legal');
        $token = $this->makeToken((string) config('ipro.portal_sso_secret'));

        return redirect(rtrim((string) config('ipro.legal_url'), '/') . '/api/sso?token=' . urlencode($token));
    }

    /** Portal → app Sales (Next.js, path /sales) — verifikasi di /sales/api/sso. */
    public function toSales(): RedirectResponse
    {
        $this->pastikanBoleh('sales');
        $token = $this->makeToken((string) config('ipro.portal_sso_secret'));

        return redirect(rtrim((string) config('ipro.sales_url'), '/') . '/api/sso?token=' . urlencode($token));
    }

    /**
     * Rutenya hanya dijaga `auth` — itu cuma menjawab "sudah login?", bukan "boleh
     * masuk portal ini?". Tanpa pemeriksaan ini, siapa pun yang punya akun bisa
     * mengetik /sso/to-legal dan menerima token handoff yang sah untuk portal Legal.
     * Menyembunyikan kartunya di dashboard tidak menutup apa pun.
     */
    private function pastikanBoleh(string $modul): void
    {
        abort_unless(\App\Support\Akses::boleh(config('akses.modul.'.$modul)), 403);
    }

    /** Token handoff HMAC (payload base64 + tanda tangan), umur 60 detik, nonce sekali pakai. */
    private function makeToken(string $secret): string
    {
        $user = Auth::user();
        abort_unless($user, 403);
        abort_if($secret === '', 500, 'Shared secret SSO belum diset.');

        $payload = base64_encode((string) json_encode([
            'email' => $user->email,
            'name'  => $user->name,
            'jti'   => bin2hex(random_bytes(16)),   // nonce: token hanya boleh dipakai sekali
            'exp'   => now()->addSeconds(60)->timestamp,
        ]));

        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }
}
