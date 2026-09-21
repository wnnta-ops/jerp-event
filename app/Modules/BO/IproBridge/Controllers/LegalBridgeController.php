<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Controllers;

use App\Modules\BO\IproBridge\Services\LegalAjbBridge;
use App\Modules\BO\IproBridge\Support\LegalBridgeSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API jembatan untuk modul Legal (app/legal, Next.js) — TANPA sesi.
 *
 * Legal container tidak punya sesi Laravel dan tidak boleh menyentuh MySQL,
 * jadi ia memanggil endpoint ini dengan tanda tangan HMAC (lihat
 * LegalBridgeSignature). Semua endpoint POST + body JSON; ini disengaja supaya
 * tidak ada query string yang perlu ikut ditandatangani — perbedaan halus
 * persen-encoding antara Node, nginx, dan PHP akan membuat tanda tangan yang
 * sah ditolak secara acak, dan itu jenis kegagalan yang paling sulit dilacak.
 */
class LegalBridgeController
{
    public function __construct(private readonly LegalAjbBridge $bridge) {}

    /** Daftar SPR (Final) — untuk penaut di formulir dokumen AJB modul Legal. */
    public function spr(Request $request): JsonResponse
    {
        if ($tolak = $this->tolak($request)) {
            return $tolak;
        }

        return response()->json([
            'ok'   => true,
            'data' => $this->bridge->cariSpr(
                $request->input('q'),
                (int) $request->input('limit', 20),
            ),
        ]);
    }

    /** Dorong akta AJB dari direktori Legal → Detail AJB (Booked Revenue). */
    public function simpanAjb(Request $request): JsonResponse
    {
        if ($tolak = $this->tolak($request)) {
            return $tolak;
        }

        $hasil = $this->bridge->simpan($request->all());

        // 422, bukan 500: semua kegagalan di sini adalah penolakan aturan bisnis
        // yang sudah punya pesan sendiri, dan sisi Legal menampilkannya apa adanya.
        return response()->json($hasil, $hasil['ok'] ? 200 : 422);
    }

    /** Lepas tautan (dokumen Legal dihapus atau tipenya bukan AJB lagi). */
    public function lepasAjb(Request $request): JsonResponse
    {
        if ($tolak = $this->tolak($request)) {
            return $tolak;
        }

        $hasil = $this->bridge->lepas((string) $request->input('legal_doc_id', ''));

        return response()->json($hasil, $hasil['ok'] ? 200 : 422);
    }

    /** Null bila permintaan sah; selain itu respons 401 yang sudah jadi. */
    private function tolak(Request $request): ?JsonResponse
    {
        $alasan = LegalBridgeSignature::periksa($request);

        return $alasan === null ? null : response()->json(['ok' => false, 'pesan' => $alasan], 401);
    }
}
