<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Master\Sales\Models\SalesPerson;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Sales / Marketing (eProperty: Setup Sales → Master Sales).
 */
class SalesPersonController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        return Inertia::render('SetupSales/Sales/Index', [
            'rows' => SalesPerson::query()->with('manager:id,name', 'user:id,name')
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
                ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
                ->orderBy('name')->paginate(25)->withQueryString(),
            'filters'  => $request->only(['search', 'active']),
            'managers' => SalesPerson::where('is_active', true)->orderBy('name')->get(['id', 'name', 'level']),
            'users'    => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        SalesPerson::create($this->validateData($request));

        return back()->with('success', 'Sales ditambahkan.');
    }

    public function update(Request $request, SalesPerson $sales): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $data = $this->validateData($request, $sales->id);
        abort_if(($data['manager_id'] ?? null) === $sales->id, 422, 'Manager tidak boleh diri sendiri.');
        $sales->update($data);

        return back()->with('success', 'Sales diperbarui.');
    }

    public function destroy(Request $request, SalesPerson $sales): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $sales->delete();

        return back()->with('success', 'Sales dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'code'       => ['required', 'string', 'max:32'],
            'name'       => ['required', 'string', 'max:150'],
            'type'       => ['required', 'in:arebi,non_arebi'],
            'level'      => ['required', 'in:kantor,koordinator,sales'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'email'      => ['nullable', 'email', 'max:150'],
            'manager_id' => ['nullable', 'integer', 'exists:sales_persons,id'],
            'user_id'    => ['nullable', 'integer', 'exists:users,id'],
            'joined_at'  => ['nullable', 'date'],
            'is_active'  => ['boolean'],
        ]);
        $dup = SalesPerson::where('code', $v['code'])->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))->exists();
        abort_if($dup, 422, "Kode sales \"{$v['code']}\" sudah dipakai.");

        // Hierarki atasan: kantor (tanpa atasan) → koordinator (atasan = kantor) → sales (atasan = koordinator).
        if ($v['level'] === 'kantor') {
            $v['manager_id'] = null;
        } else {
            $expected = $v['level'] === 'koordinator' ? 'kantor' : 'koordinator';
            abort_if(empty($v['manager_id']), 422, "Atasan wajib dipilih untuk level {$v['level']}.");
            $manager = SalesPerson::find($v['manager_id']);
            abort_if(! $manager || $manager->level !== $expected, 422, "Atasan untuk level {$v['level']} harus ber-level {$expected}.");
        }

        return $v;
    }
}
