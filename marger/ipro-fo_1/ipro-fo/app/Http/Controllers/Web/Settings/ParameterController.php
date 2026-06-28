<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ParameterController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.setup.parameter'), 403);

        $params = DB::table('parameters')
            ->where('tenant_id', tenant_id())
            ->orderBy('group')->orderBy('key')->get()
            ->groupBy('group');

        return Inertia::render('Settings/Parameters/Index', [
            'groups' => $params,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.parameter'), 403);

        $payload = $request->validate([
            'updates'         => 'required|array',
            'updates.*.id'    => 'required|integer|exists:parameters,id',
            'updates.*.value' => 'required|string',
        ]);

        foreach ($payload['updates'] as $u) {
            DB::table('parameters')->where('id', $u['id'])->update(['value' => $u['value'], 'updated_at' => now()]);
        }

        return back()->with('success', 'Parameter berhasil disimpan.');
    }
}
