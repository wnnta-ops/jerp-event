<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Unit::class);
        return UnitResource::collection($this->service->paginate(
            $request->only(['search', 'project_id', 'cluster_id', 'status', 'unit_type_id']),
            (int) $request->integer('per_page', 50),
        ));
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);
        return UnitResource::make($unit->load(['cluster', 'block', 'unitType', 'siteplanPoint']));
    }
}
