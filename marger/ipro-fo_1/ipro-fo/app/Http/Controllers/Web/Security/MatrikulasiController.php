<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Security;

use App\Domain\Foundation\Tenant\Models\Company;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matrikulasi akses — matriks user × perusahaan dan user × project.
 */
class MatrikulasiController extends Controller
{
    public function company(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);
        $access = DB::table('sec_user_companies')->select('user_id', 'company_id')->get()
            ->groupBy('user_id')->map(fn ($g) => $g->pluck('company_id')->all());

        return Inertia::render('Security/Matrikulasi', [
            'mode'     => 'company',
            'title'    => 'Matrikulasi Perusahaan',
            'users'    => User::orderBy('name')->get(['id', 'name', 'email']),
            'items'    => Company::orderBy('name')->get(['id', 'code', 'name']),
            'access'   => $access,
        ]);
    }

    public function project(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);
        $access = DB::table('sec_user_projects')->select('user_id', 'project_id')->get()
            ->groupBy('user_id')->map(fn ($g) => $g->pluck('project_id')->all());

        return Inertia::render('Security/Matrikulasi', [
            'mode'     => 'project',
            'title'    => 'Matrikulasi Project',
            'users'    => User::orderBy('name')->get(['id', 'name', 'email']),
            'items'    => Project::orderBy('name')->get(['id', 'code', 'name']),
            'access'   => $access,
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('security.manage'), 403);
        $v = $request->validate([
            'mode'    => ['required', 'in:company,project'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'item_id' => ['required', 'integer'],
        ]);
        $table = $v['mode'] === 'company' ? 'sec_user_companies' : 'sec_user_projects';
        $col   = $v['mode'] === 'company' ? 'company_id' : 'project_id';

        $existing = DB::table($table)->where('user_id', $v['user_id'])->where($col, $v['item_id'])->first();
        if ($existing) {
            DB::table($table)->where('id', $existing->id)->delete();
        } else {
            DB::table($table)->insert([
                'tenant_id' => tenant_id() ?? default_tenant_id(), 'user_id' => $v['user_id'], $col => $v['item_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return back(303)->with('success', 'Akses diperbarui.');
    }
}
