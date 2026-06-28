<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.role.view'), 403);

        return Inertia::render('Settings/Roles/Index', [
            'roles' => Role::with('permissions:id,name')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('system.role.update'), 403);

        return Inertia::render('Settings/Roles/Create', [
            'all_permissions' => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'label'])->groupBy('module'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system.role.update'), 403);

        $data = $request->validate([
            'name'          => 'required|string|max:100|unique:roles,name',
            'description'   => 'nullable|string|max:255',
            'permissions'   => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->update(['description' => $data['description'] ?? null]);
        $role->syncPermissions($data['permissions']);

        return redirect()->route('settings.roles.index')->with('success', "Role {$role->name} dibuat.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()?->can('system.role.update'), 403);

        if (strtolower($role->name) === 'superadmin') {
            return back()->with('error', 'Role SuperAdmin tidak boleh dihapus.');
        }
        $inUse = User::role($role->name)->count();
        if ($inUse > 0) {
            return back()->with('error', "Role {$role->name} masih dipakai {$inUse} user — lepaskan dulu dari user terkait sebelum dihapus.");
        }

        $name = $role->name;
        $role->delete();

        return redirect()->route('settings.roles.index')->with('success', "Role {$name} dihapus.");
    }

    public function edit(Role $role): Response
    {
        abort_unless(auth()->user()?->can('system.role.update'), 403);

        return Inertia::render('Settings/Roles/Edit', [
            'role'             => $role->load('permissions:id,name'),
            'all_permissions'  => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'label'])->groupBy('module'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()?->can('system.role.update'), 403);

        $data = $request->validate([
            'description'  => 'nullable|string|max:255',
            'permissions'  => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->update(['description' => $data['description'] ?? null]);
        $role->syncPermissions($data['permissions']);

        return redirect()->route('settings.roles.index')->with('success', "Role {$role->name} diperbarui.");
    }
}
