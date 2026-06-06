<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side proxy untuk data wilayah Indonesia (emsifa public API).
 *
 * Frontend (Alpine) fetch ke endpoint ini (same-origin) supaya tidak kena
 * CORS — GitHub Pages tidak lagi mengirim header Access-Control-Allow-Origin.
 * Respons di-cache lama karena data wilayah praktis statis.
 */
class WilayahController extends Controller
{
    private const BASE = 'https://emsifa.github.io/api-wilayah-indonesia/api';

    private const TTL = 60 * 60 * 24 * 30; // 30 hari

    public function provinces(): JsonResponse
    {
        return $this->proxy('provinces.json', 'wilayah:provinces');
    }

    public function regencies(string $provinceId): JsonResponse
    {
        return $this->proxy("regencies/{$provinceId}.json", "wilayah:regencies:{$provinceId}");
    }

    public function districts(string $regencyId): JsonResponse
    {
        return $this->proxy("districts/{$regencyId}.json", "wilayah:districts:{$regencyId}");
    }

    public function villages(string $districtId): JsonResponse
    {
        return $this->proxy("villages/{$districtId}.json", "wilayah:villages:{$districtId}");
    }

    private function proxy(string $path, string $cacheKey): JsonResponse
    {
        $data = Cache::remember($cacheKey, self::TTL, function () use ($path) {
            $response = Http::timeout(15)
                ->retry(2, 200)
                ->acceptJson()
                ->get(self::BASE.'/'.$path);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        });

        if ($data === null) {
            // Jangan cache kegagalan
            Cache::forget($cacheKey);

            return response()->json([], 502);
        }

        return response()->json($data);
    }
}
