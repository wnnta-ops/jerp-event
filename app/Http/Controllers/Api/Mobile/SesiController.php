<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Modules\FO\Approval\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Perangkat & sesi app approver: tiap login = satu token Sanctum (nama = perangkat).
 * Hanya token milik user sendiri yang terlihat / bisa dicabut.
 */
class SesiController
{
    public function index(Request $request): JsonResponse
    {
        $kini = $request->user()->currentAccessToken()->getKey();

        $data = $request->user()->tokens()
            ->latest('last_used_at')->latest('id')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'perangkat' => $t->name,
                'masuk_at' => $t->created_at?->toIso8601String(),
                'terakhir_at' => $t->last_used_at?->toIso8601String(),
                'ini' => $t->id === $kini,
            ]);

        return response()->json(['data' => $data]);
    }

    /** Keluarkan satu perangkat lain (perangkat ini pakai /auth/logout). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_if($id === $request->user()->currentAccessToken()->getKey(), 422, 'Gunakan Keluar untuk perangkat ini.');
        $hapus = $request->user()->tokens()->whereKey($id)->delete();
        abort_unless($hapus > 0, 404, 'Perangkat tidak ditemukan.');
        PushSubscription::hapusUntukToken($id);

        return response()->json(['ok' => true]);
    }

    /** Keluar dari semua perangkat, termasuk yang ini. */
    public function destroyAll(Request $request): JsonResponse
    {
        $n = $request->user()->tokens()->delete();
        PushSubscription::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ok' => true, 'jumlah' => $n]);
    }
}
