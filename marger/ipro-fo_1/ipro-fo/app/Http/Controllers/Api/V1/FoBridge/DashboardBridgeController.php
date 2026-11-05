<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FoBridge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\DashboardController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * Dashboard BO untuk app approver (PWA) — dipanggil FO lewat VerifyFoBridge atas nama user FO.
 *
 * Isinya diambil dari DashboardController apa adanya (props Inertia sebagai JSON), jadi hak akses
 * per sub-dashboard (dashboard.{grup}.view), periode, dan hitungan grafik SAMA dengan halaman
 * /dashboard — tidak ada rumus yang disalin. Tanpa izin satu pun → allowed = false.
 */
class DashboardBridgeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $boleh = array_values(array_filter(
            array_keys(DashboardController::GRUP),
            fn (string $g) => (bool) $user?->can("dashboard.{$g}.view"),
        ));
        if ($boleh === []) {
            return response()->json(['allowed' => false, 'grup' => [], 'charts' => (object) []]);
        }

        // Hanya parameter periode yang diteruskan; ?grup= tidak dipakai (selalu semua yang diizinkan).
        $sub = Request::create('/dashboard', 'GET', array_filter($request->only(['date_from', 'date_to'])));
        $sub->setUserResolver(fn () => $user);
        $sub->headers->set('X-Inertia', 'true');

        $res = app(DashboardController::class)($sub);
        abort_unless($res instanceof InertiaResponse, 500, 'Dashboard BO tidak bisa dimuat.');
        $props = $res->toResponse($sub)->getData(true)['props'] ?? [];

        return response()->json([
            'allowed' => true,
            'grup' => array_map(fn (string $g) => [
                'key' => $g,
                'judul' => DashboardController::GRUP_JUDUL[$g] ?? $g,
                'charts' => DashboardController::GRUP[$g],
            ], $boleh),
            'periode' => $props['periode'] ?? null,
            'charts' => (object) ($props['charts'] ?? []),
        ]);
    }
}
