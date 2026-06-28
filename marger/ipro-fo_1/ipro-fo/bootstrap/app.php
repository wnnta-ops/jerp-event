<?php

declare(strict_types=1);

use App\Http\Middleware\AuditContext;
use App\Http\Middleware\EnsureMfa;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\SetTenant;
use App\Http\Middleware\VerifyHmacSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        api:      __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health:   '/healthz',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Host lama (ts.net) → halaman "pindah ke domain baru" (jalan paling awal).
        $middleware->web(prepend: [
            \App\Http\Middleware\RedirectLegacyDomain::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            'auth.api'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'set-tenant'      => SetTenant::class,
            'audit-context'   => AuditContext::class,
            'ensure-mfa'      => EnsureMfa::class,
            'idempotency'     => Idempotency::class,
            'verify-hmac'     => VerifyHmacSignature::class,
            'permission'      => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role'            => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        $middleware->trustProxies(at: '*');

        // Webhook gateway pembayaran tidak mengirim CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (\App\Exceptions\BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code'       => $e->errorCode(),
                        'message'    => $e->getMessage(),
                        'details'    => $e->details(),
                        'request_id' => $request->header('X-Request-ID'),
                    ],
                ], $e->httpStatus());
            }
        });

        // Phase 7 — Render friendly Inertia error pages for web (Indonesian).
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();

            // #79: 419 (CSRF / "Page Expired") pada request Inertia → JANGAN render halaman Error
            // (yang mengirim X-Inertia + 419 sehingga visit Inertia gugur diam-diam: aksi gagal
            // tanpa pesan & jurnal tak terbentuk). Redirect kembali dgn flash → Inertia memuat
            // ulang halaman (token CSRF segar) & menampilkan pesan; user tinggal mengulang aksi.
            if ($status === 419 && $request->header('X-Inertia')) {
                return back()->with('error', 'Sesi keamanan halaman kedaluwarsa — halaman dimuat ulang. Silakan ulangi aksi Anda.');
            }

            // Friendly pages for client errors (404/403/419) selalu aktif — bukan debug-worthy.
            // Server errors (500/503): pakai debug page HANYA saat APP_DEBUG=true (dev lokal).
            // Saat APP_DEBUG=false (mis. diekspos online via tunnel), tampilkan halaman ramah
            // sehingga stack trace / isi .env TIDAK pernah bocor ke publik.
            $clientErrors = in_array($status, [403, 404, 419], true);
            $serverErrors = in_array($status, [500, 503], true) && ! config('app.debug');

            if ($clientErrors || $serverErrors) {
                return \Inertia\Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })
    ->create();
