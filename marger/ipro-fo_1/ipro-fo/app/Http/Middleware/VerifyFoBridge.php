<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jembatan server-ke-server dari J-ERP (FO) untuk app approver (PWA): FO meneruskan
 * permintaan atas nama user yang sudah login di FO. Ditandatangani HMAC-SHA256 dengan
 * secret SSO yang sama (config sso.secret) — lihat FO BoInboxBridge::tandaTangan.
 *
 *   X-Bridge-Email : email user FO (dipetakan lewat sso.email_map seperti SSO)
 *   X-Bridge-Ts    : unix time; ditolak bila selisih > 60 detik
 *   X-Bridge-Nonce : sekali pakai (anti-replay)
 *   X-Bridge-Sig   : hmac(ts \n nonce \n METHOD \n /path \n email \n sha256(body))
 *
 * Sesudah lolos, user BO di-set sebagai user request (tanpa sesi) supaya
 * set-tenant / audit-context / ActOnApproval berjalan seperti di web.
 *
 * Batas permintaan dihitung PER USER di sini (bukan throttle:api grup API, yang memakai IP
 * sebelum user dikenal — semua permintaan jembatan datang dari satu IP server FO, sehingga
 * seluruh approver + job push akan berbagi satu kuota). Route jembatan melepas throttle grup.
 */
final class VerifyFoBridge
{
    private const JENDELA_DETIK = 60;

    public const MAKS_PER_MENIT = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('sso.secret', '');
        abort_if($secret === '', 403, 'Jembatan FO tidak terkonfigurasi.');

        $email = (string) $request->header('X-Bridge-Email', '');
        $ts = (string) $request->header('X-Bridge-Ts', '');
        $nonce = (string) $request->header('X-Bridge-Nonce', '');
        $sig = (string) $request->header('X-Bridge-Sig', '');
        abort_if($email === '' || $nonce === '' || $sig === '' || ! ctype_digit($ts), 403, 'Tanda tangan FO tidak lengkap.');
        abort_if(abs(now()->timestamp - (int) $ts) > self::JENDELA_DETIK, 403, 'Tanda tangan FO kedaluwarsa.');

        $expected = hash_hmac('sha256', self::kanonik($ts, $nonce, $request->method(), '/'.ltrim($request->path(), '/'), $email, $request->getContent()), $secret);
        abort_unless(hash_equals($expected, $sig), 403, 'Tanda tangan FO tidak sah.');
        abort_unless(Cache::add('fo-bridge:nonce:'.$nonce, 1, self::JENDELA_DETIK * 2), 403, 'Permintaan FO sudah dipakai.');

        $email = (string) (config('sso.email_map')[$email] ?? $email);
        $user = User::where('email', $email)->first();
        abort_unless($user && $user->is_active, 403, "User '{$email}' tidak ada / nonaktif di BO.");

        $kunciBatas = self::kunciBatas((int) $user->id);
        abort_if(RateLimiter::tooManyAttempts($kunciBatas, self::MAKS_PER_MENIT), 429, 'Terlalu banyak permintaan dari app approver. Coba lagi sebentar.');
        RateLimiter::hit($kunciBatas, 60);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    public static function kunciBatas(int $userId): string
    {
        return 'fo-bridge:'.$userId;
    }

    public static function kanonik(string $ts, string $nonce, string $method, string $path, string $email, string $body): string
    {
        return implode("\n", [$ts, $nonce, strtoupper($method), $path, $email, hash('sha256', $body)]);
    }
}
