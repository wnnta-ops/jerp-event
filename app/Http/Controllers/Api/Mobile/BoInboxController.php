<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Modules\FO\Approval\Services\BoInboxBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * BO Approval Inbox & Dashboard untuk app approver (PWA) — FO meneruskan ke BO lewat BoInboxBridge
 * atas nama user yang login (token Sanctum FO). Status & pesan BO diteruskan apa adanya.
 */
class BoInboxController
{
    public function __construct(private readonly BoInboxBridge $bridge) {}

    public function index(Request $request): JsonResponse
    {
        return $this->balas($this->bridge->get($this->email($request)));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->balas($this->bridge->get($this->email($request), '/history'));
    }

    /** Chain yang melewati saya + ringkasan keputusan (tab Profil). */
    public function profil(Request $request): JsonResponse
    {
        return $this->balas($this->bridge->get($this->email($request), '/profil'));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->balas($this->bridge->get($this->email($request), "/{$id}"));
    }

    /** Isi dokumen untuk preview / unduh di PWA — byte & Content-Type dari BO apa adanya. */
    public function dokumen(Request $request, int $id, string $kunci): Response
    {
        $res = $this->bridge->dokumen($this->email($request), $id, $kunci);
        $nama = $res['nama'] ?? 'dokumen';

        return response($res['isi'], $res['status'], array_filter([
            'Content-Type' => $res['tipe'],
            'Content-Disposition' => $res['status'] === 200 ? 'inline; filename="'.addcslashes($nama, '"\\').'"' : null,
            // Nama file untuk tombol Unduh di PWA — perlu di-expose saat PWA beda origin (lokal :8083).
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    public function act(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->tokenCan('approval:act'), 403, 'Token tidak berhak mengambil keputusan.');

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|required_if:action,reject|string|max:500',
        ], ['notes.required_if' => 'Alasan penolakan wajib diisi.']);

        return $this->balas($this->bridge->post($this->email($request), "/{$id}/act", $data));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate(['date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d']);

        return $this->balas($this->bridge->dashboard($this->email($request), $data));
    }

    private function email(Request $request): string
    {
        return (string) $request->user()->email;
    }

    /** @param array{status:int, body:array<string,mixed>} $res */
    private function balas(array $res): JsonResponse
    {
        return response()->json($res['body'], $res['status']);
    }
}
