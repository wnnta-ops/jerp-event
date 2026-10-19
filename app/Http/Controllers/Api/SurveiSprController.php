<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar SPR untuk aplikasi Survei: No. SPR, nama customer, unit — sengaja hanya itu
 * (tanpa NIK/HP/email). Baca saja.
 *
 * GET /api/survei/spr?q=&status=AKTIF|BATAL|SEMUA&per_page=100&page=1
 * Header: Authorization: Bearer <SURVEI_API_KEY>   (atau X-Api-Key: <kunci>)
 */
class SurveiSprController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $kunci = (string) config('services.survei.api_key');
        if ($kunci === '') {
            return response()->json(['message' => 'API survei belum diaktifkan (SURVEI_API_KEY kosong).'], 503);
        }
        $dikirim = (string) ($request->bearerToken() ?: $request->header('X-Api-Key'));
        if ($dikirim === '' || ! hash_equals($kunci, $dikirim)) {
            return response()->json(['message' => 'API key tidak valid.'], 401);
        }

        $status = strtoupper((string) $request->query('status', 'AKTIF'));
        $q = trim((string) $request->query('q', ''));
        $perPage = max(1, min(500, (int) $request->query('per_page', 100)));

        $hasil = Kontrak::query()
            ->where('tahap', 'SPR')
            ->when($status !== 'SEMUA', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('no_kontrak', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$q}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('kode_unit', 'like', "%{$q}%"))))
            ->with(['customer:id,full_name', 'unit:id,kode_unit'])
            ->orderBy('no_kontrak')
            ->paginate($perPage, ['id', 'no_kontrak', 'customer_id', 'unit_id', 'status']);

        return response()->json([
            'data' => $hasil->getCollection()->map(fn (Kontrak $k) => [
                'no_spr' => $k->no_kontrak,
                'nama' => $k->customer?->full_name,
                'unit' => $k->unit?->kode_unit,
                'status' => $k->status,
            ])->values(),
            'meta' => [
                'page' => $hasil->currentPage(),
                'per_page' => $hasil->perPage(),
                'total' => $hasil->total(),
                'last_page' => $hasil->lastPage(),
            ],
        ]);
    }
}
