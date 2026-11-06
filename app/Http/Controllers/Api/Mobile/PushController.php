<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Modules\FO\Approval\Models\PushSubscription;
use App\Modules\FO\Approval\Services\PushPengirim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Langganan notifikasi push app approver (PWA): satu baris per perangkat, dikenali dari endpoint.
 * Pengirimannya oleh job tiap menit (pwa:push), kecuali "Kirim notifikasi uji".
 */
class PushController
{
    public function config(): JsonResponse
    {
        return response()->json([
            'aktif' => app(PushPengirim::class)->siapWeb(),
            // APK Android: notifikasi lewat FCM (tanpa VAPID).
            'fcm_aktif' => app(\App\Modules\FO\Approval\Services\FcmPengirim::class)->siap(),
            'public_key' => config('push.vapid.public_key'),
            'jam_harian' => config('push.jam_harian'),
        ]);
    }

    /** Status langganan perangkat ini (endpoint dikirim app). */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        $s = $this->milik($request, $data['endpoint']);

        return response()->json(['data' => $s ? $this->bentuk($s) : null]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        // APK Android mengirim endpoint "fcm:<token>" tanpa kunci Web Push.
        $fcm = str_starts_with((string) $request->input('endpoint'), 'fcm:');
        $data = $request->validate([
            'endpoint' => $fcm ? 'required|string|min:20|max:2000' : 'required|url|max:2000',
            'keys.p256dh' => $fcm ? 'nullable' : 'required|string|max:255',
            'keys.auth' => $fcm ? 'nullable' : 'required|string|max:255',
            'perangkat' => 'nullable|string|max:120',
        ] + $this->aturanPilihan());

        $s = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                // Endpoint yang sama dipakai user lain di HP ini → pindah ke user yang sekarang login.
                'user_id' => $request->user()->id,
                'token_id' => $request->user()->currentAccessToken()->getKey(),
                'endpoint' => $data['endpoint'],
                'p256dh' => $fcm ? '' : $data['keys']['p256dh'],
                'auth' => $fcm ? '' : $data['keys']['auth'],
                'perangkat' => $data['perangkat'] ?? null,
            ] + $this->pilihan($data),
        );

        return response()->json(['data' => $this->bentuk($s)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000'] + $this->aturanPilihan());
        $s = $this->milik($request, $data['endpoint']);
        abort_if($s === null, 404, 'Notifikasi belum aktif di perangkat ini.');
        $s->update($this->pilihan($data));

        return response()->json(['data' => $this->bentuk($s)]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        $this->milik($request, $data['endpoint'])?->delete();

        return response()->json(['ok' => true]);
    }

    public function uji(Request $request, PushPengirim $pengirim): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        $s = $this->milik($request, $data['endpoint']);
        abort_if($s === null, 404, 'Notifikasi belum aktif di perangkat ini.');
        abort_unless($pengirim->siapUntuk($s), 503, $s->fcm() ? 'Notifikasi APK belum dikonfigurasi di server (FCM).' : 'Notifikasi push belum dikonfigurasi di server (VAPID).');

        $ok = $pengirim->kirim($s, [
            'title' => 'Notifikasi uji J-ERP',
            'body' => 'Notifikasi di perangkat ini sudah berfungsi.',
            'url' => (string) config('push.buka_url'),
            'tag' => 'uji',
        ]);
        if (! $ok) {
            $s->delete();
            abort(410, 'Langganan perangkat ini sudah tidak berlaku — nyalakan ulang notifikasi.');
        }

        return response()->json(['ok' => true]);
    }

    private function milik(Request $request, string $endpoint): ?PushSubscription
    {
        return PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function aturanPilihan(): array
    {
        return [
            'giliran' => 'sometimes|boolean',
            'ajuan' => 'sometimes|boolean',
            'harian' => 'sometimes|boolean',
            'jam_harian' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    /** @return array<string, mixed> */
    private function pilihan(array $data): array
    {
        return array_intersect_key($data, array_flip(['giliran', 'ajuan', 'harian', 'jam_harian']));
    }

    /** @return array<string, mixed> */
    private function bentuk(PushSubscription $s): array
    {
        return [
            'giliran' => $s->giliran,
            'ajuan' => $s->ajuan,
            'harian' => $s->harian,
            'jam_harian' => $s->jam_harian,
        ];
    }
}
