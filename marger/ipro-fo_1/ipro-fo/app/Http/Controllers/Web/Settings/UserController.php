<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.user.view'), 403);

        $users = User::query()
            ->with('roles:id,name')
            ->when($request->input('search'), fn ($q, $term) => $q->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->paginate(50);

        return Inertia::render('Settings/Users/Index', [
            'users'   => $users,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('system.user.create'), 403);

        return Inertia::render('Settings/Users/Create', [
            'roles' => Role::select('id', 'name', 'description')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system.user.create'), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'email'       => 'required|email|unique:users,email',
            'phone'       => 'nullable|string|max:30',
            'password'    => 'required|string|min:12',
            'is_active'   => 'boolean',
            'roles'       => 'required|array|min:1',
            'roles.*'     => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'tenant_id'         => tenant_id(),
            'name'              => $data['name'],
            'email'             => $data['email'],
            'phone'             => $data['phone'] ?? null,
            'password'          => Hash::make($data['password']),
            'is_active'         => $data['is_active'] ?? true,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
        $user->syncRoles($data['roles']);

        return redirect()->route('settings.users.index')
            ->with('success', "User {$user->name} berhasil dibuat.");
    }

    public function edit(User $user): Response
    {
        abort_unless(auth()->user()?->can('system.user.update'), 403);

        return Inertia::render('Settings/Users/Edit', [
            'user'  => $user->load('roles:id,name'),
            'roles' => Role::select('id', 'name', 'description')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->can('system.user.update'), 403);

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'phone'     => 'nullable|string|max:30',
            'is_active' => 'boolean',
            'roles'     => 'required|array|min:1',
            'roles.*'   => 'string|exists:roles,name',
            'password'  => 'nullable|string|min:12',
        ]);

        $user->update([
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? $user->is_active,
            ...($data['password'] ? ['password' => Hash::make($data['password']), 'password_changed_at' => now()] : []),
        ]);
        $user->syncRoles($data['roles']);

        return redirect()->route('settings.users.index')->with('success', 'User diperbarui.');
    }

    public function block(User $user): RedirectResponse
    {
        abort_unless(auth()->user()?->can('system.user.block'), 403);

        $user->update(['is_blocked' => ! $user->is_blocked]);
        return back()->with('success', $user->is_blocked ? 'User diblokir.' : 'Blokir dilepas.');
    }
}
