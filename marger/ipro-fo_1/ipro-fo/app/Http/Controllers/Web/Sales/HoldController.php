<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Hold\Actions\HoldUnit;
use App\Domain\Sales\Hold\Actions\ReleaseHold;
use App\Domain\Sales\Hold\Models\UnitHold;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HoldController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.hold.view'), 403);

        $rows = UnitHold::query()
            ->with([
                'unit:id,unit_code,project_id,status',
                'customer:id,code,full_name,phone',
            ])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('held_at')
            ->paginate(50);

        return Inertia::render('Sales/Hold/Index', [
            'holds'   => $rows,
            'filters' => $request->only(['status']),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('sales.hold.create'), 403);

        return Inertia::render('Sales/Hold/Create', [
            'units'   => Unit::where('status', 'available')
                ->with(['cluster:id,name', 'unitType:id,name'])
                ->get(['id', 'unit_code', 'cluster_id', 'unit_type_id', 'current_price']),
            'max_days'=> config('domain.hold.max_days', 14),
        ]);
    }

    public function store(Request $request, HoldUnit $action, CreateCustomer $createCustomer): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.hold.create'), 403);

        $data = $request->validate([
            'unit_id'          => 'required|integer|exists:units,id',
            'customer_id'      => 'nullable|integer|exists:customers,id',
            'duration_minutes' => 'nullable|integer|min:60',
            'reason'           => 'nullable|string|max:255',
            // Customer baru (inline) — dipakai bila customer_id tidak dipilih.
            'new_customer.full_name' => 'required_without:customer_id|string|max:150',
            'new_customer.phone'     => 'nullable|string|max:30',
            'new_customer.id_number' => 'nullable|string|max:32',
        ]);

        // Buat customer baru langsung dari form Hold bila belum memilih customer existing.
        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            $nc = $request->input('new_customer', []);
            abort_unless($request->user()?->can('master.customer.create'), 403, 'Tidak berwenang membuat customer.');
            $customer = $createCustomer->execute([
                'full_name' => $nc['full_name'],
                'phone'     => $nc['phone'] ?? null,
                'id_type'   => 'KTP',
                'id_number' => $nc['id_number'] ?? null,
            ]);
            $customerId = $customer->id;
        }

        $action->execute(
            unitId:          $data['unit_id'],
            customerId:      $customerId,
            durationMinutes: $data['duration_minutes'] ?? (config('domain.hold.default_days', 7) * 24 * 60),
            reason:          $data['reason'] ?? null,
        );

        return redirect()->route('holds.index')->with('success', 'Unit berhasil di-hold.');
    }

    public function release(UnitHold $hold, ReleaseHold $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('sales.hold.release'), 403);

        $action->execute($hold);

        return back()->with('success', 'Hold dilepas, unit kembali AVAILABLE.');
    }
}
