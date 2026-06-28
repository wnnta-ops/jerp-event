<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsProject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Master Project (Contractor Management → Setup → Project). */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        return Inertia::render('BusinessSupport/Contractor/Project', [
            'projects' => BsProject::orderBy('code')->get(['id', 'code', 'name', 'dept', 'is_active', 'notes']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $this->validateData($request);
        BsProject::create(array_merge($v, ['tenant_id' => tenant_id(), 'created_by' => $request->user()->id]));

        return back()->with('success', 'Project ditambahkan.');
    }

    public function update(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $project->update($this->validateData($request));

        return back()->with('success', 'Project diperbarui.');
    }

    public function destroy(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $project->delete();

        return back()->with('success', 'Project dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'code'      => ['required', 'string', 'max:30'],
            'name'      => ['required', 'string', 'max:150'],
            'dept'      => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'notes'     => ['nullable', 'string', 'max:200'],
        ]);
    }
}
