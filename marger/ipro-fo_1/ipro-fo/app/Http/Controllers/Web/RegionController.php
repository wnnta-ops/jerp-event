<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Foundation\Region\Models\Region;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint cascading wilayah untuk dropdown bertingkat.
 *   GET /regions?parent=        → daftar Provinsi (level 1)
 *   GET /regions?parent=11      → Kab/Kota di provinsi 11
 *   GET /regions?parent=11.01   → Kecamatan di kab 11.01
 *   GET /regions?parent=11.01.01→ Kelurahan di kec 11.01.01
 */
class RegionController extends Controller
{
    public function children(Request $request): JsonResponse
    {
        $parent = trim((string) $request->query('parent', ''));

        $rows = Region::query()
            ->when($parent === '', fn ($q) => $q->where('level', 1))
            ->when($parent !== '', fn ($q) => $q->where('parent_code', $parent))
            ->orderBy('name')
            ->limit(2000)
            ->get(['code', 'name']);

        return response()->json($rows);
    }
}
