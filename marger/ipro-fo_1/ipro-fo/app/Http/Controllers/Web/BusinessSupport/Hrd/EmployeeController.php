<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Hrd;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('datacenter'), 403);

        $rows = BsEmployee::query()
            ->with('costCenter:id,code,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%")->orWhere('position', 'like', "%{$s}%")))
            ->when($request->input('status') !== null && $request->input('status') !== '', fn ($q) => $q->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Hrd/Employees', [
            'employees'   => $rows,
            'filters'     => $request->only(['search', 'status']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'summary'     => [
                'active'  => BsEmployee::where('is_active', true)->count(),
                'payroll' => (float) BsEmployee::where('is_active', true)->sum(\Illuminate\Support\Facades\DB::raw('base_salary + allowance')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsEmployee::create($this->data($request) + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Karyawan ditambahkan.');
    }

    public function update(Request $request, BsEmployee $employee): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $employee->update($this->data($request));

        return back()->with('success', 'Data karyawan diperbarui.');
    }

    public function destroy(Request $request, BsEmployee $employee): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $employee->delete();

        return back()->with('success', 'Karyawan dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request): array
    {
        return $request->validate([
            'nik'            => ['nullable', 'string', 'max:32'],
            'name'           => ['required', 'string', 'max:150'],
            'position'       => ['nullable', 'string', 'max:100'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'join_date'      => ['nullable', 'date'],
            'base_salary'    => ['required', 'numeric', 'min:0'],
            'allowance'      => ['nullable', 'numeric', 'min:0'],
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'bank_account'   => ['nullable', 'string', 'max:60'],
            'is_active'      => ['boolean'],
        ]);
    }
}
