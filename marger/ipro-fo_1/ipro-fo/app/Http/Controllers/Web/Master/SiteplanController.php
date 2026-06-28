<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Master;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\SiteplanPoint;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\Unit\UpdateSiteplanRequest;
use App\Http\Resources\UnitResource;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SiteplanController extends Controller
{
    public function __construct(private readonly UnitService $service) {}

    public function show(Project $project): Response
    {
        $this->authorize('viewAny', Unit::class);

        return Inertia::render('Master/Unit/Siteplan', [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'meta' => $project->siteplan_meta],
            'units'   => UnitResource::collection($this->service->forSiteplan($project->id)),
        ]);
    }

    public function update(UpdateSiteplanRequest $request, Project $project, Unit $unit): JsonResponse
    {
        $this->authorize('editSiteplan', Unit::class);

        abort_unless($unit->project_id === $project->id, 404);

        SiteplanPoint::updateOrCreate(
            ['project_id' => $project->id, 'unit_id' => $unit->id],
            $request->validated(),
        );

        return response()->json(['ok' => true]);
    }
}
