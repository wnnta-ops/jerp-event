<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host lama (ts.net) → tampilkan halaman "pindah ke domain baru" + alihkan ke
 * https://java.core-system.space (path dipertahankan). Domain baru lewat tanpa diubah.
 */
class RedirectLegacyDomain
{
    /** @var string[] Host lama yang dialihkan. */
    private const LEGACY_HOSTS = ['java-residence.tail212f65.ts.net'];

    private const NEW_BASE = 'https://java.core-system.space';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getHost(), self::LEGACY_HOSTS, true)) {
            return $next($request);
        }

        $target  = self::NEW_BASE.$request->getRequestUri();
        $eTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $eBase   = htmlspecialchars(self::NEW_BASE, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="6;url={$eTarget}">
<title>Java Core System — Pindah Domain</title>
<style>
 *{box-sizing:border-box}
 body{margin:0;font-family:system-ui,'Segoe UI',Roboto,Arial,sans-serif;
   background:radial-gradient(1100px 560px at 50% -8%,#fbf3e3 0%,#efe2c8 48%,#e2d0ad 100%);
   color:#4a3526;display:flex;min-height:100vh;align-items:center;justify-content:center}
 .card{position:relative;overflow:hidden;max-width:560px;margin:20px;padding:48px 40px 40px;
   background:linear-gradient(180deg,#fdf8ef 0%,#f3e8d4 100%);
   border:1px solid #d9c39d;border-radius:20px;
   box-shadow:0 24px 60px rgba(120,72,20,.22),inset 0 1px 0 rgba(255,255,255,.85);text-align:center}
 .card::before{content:'';position:absolute;top:0;left:0;right:0;height:6px;
   background:linear-gradient(90deg,#b8460f,#f97316,#fcd9a8,#f97316,#b8460f)}
 h1{font-size:22px;margin:0 0 12px;font-weight:800;
   background:linear-gradient(180deg,#f59e0b 0%,#ea580c 60%,#b8460f 100%);
   -webkit-background-clip:text;background-clip:text;color:transparent}
 p{color:#7a6651;line-height:1.6;margin:8px 0}
 a.btn{display:inline-block;margin-top:22px;padding:15px 34px;color:#fff;text-decoration:none;border-radius:13px;font-weight:700;font-size:16px;
   background:linear-gradient(180deg,#fdba74 0%,#f97316 46%,#ea580c 56%,#b8460f 100%);
   border:1px solid #a23e0d;
   box-shadow:inset 0 1px 0 rgba(255,255,255,.6),inset 0 -2px 5px rgba(120,40,0,.35),0 8px 22px rgba(194,65,12,.38);
   text-shadow:0 1px 1px rgba(120,40,0,.45)}
 a.btn:hover{filter:brightness(1.06)}
 .url{margin-top:18px;font-family:ui-monospace,Consolas,monospace;color:#b45309;word-break:break-all;font-size:15px;font-weight:600}
 .small{margin-top:26px;font-size:13px;color:#9c8870}
</style></head><body>
<div class="card">
 <h1>🚀 Masuk ke Halaman Java Core System yang baru</h1>
 <p>Alamat lama (ts.net) sudah tidak digunakan. Silakan lanjut ke domain baru yang lebih stabil:</p>
 <a class="btn" href="{$eTarget}">Masuk ke Halaman Baru &rarr;</a>
 <div class="url">{$eBase}</div>
 <p class="small">Kamu akan dialihkan otomatis dalam beberapa detik. Mohon perbarui bookmark ke domain baru.</p>
</div>
</body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
