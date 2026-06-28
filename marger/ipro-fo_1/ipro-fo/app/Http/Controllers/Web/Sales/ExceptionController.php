<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Exception\NameChange\Actions\RequestNameChange;
use App\Domain\Sales\Exception\Reschedule\Actions\RequestReschedule;
use App\Domain\Sales\Exception\UnitChange\Actions\RequestUnitChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ExceptionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.booking.view'), 403);

        $gn = DB::table('name_change_requests')->latest()->limit(20)->get();
        $gu = DB::table('unit_change_requests')->latest()->limit(20)->get();
        $rs = DB::table('reschedule_requests')->latest()->limit(20)->get();

        return Inertia::render('Sales/Exception/Index', [
            'name_changes' => $gn,
            'unit_changes' => $gu,
            'reschedules'  => $rs,
        ]);
    }

    public function requestGn(Request $request, Booking $booking, RequestNameChange $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.gn.request'), 403);

        $data = $request->validate([
            'new_customer_id' => 'required|integer|exists:customers,id|different:'.$booking->customer_id,
            'admin_fee'       => 'nullable|numeric|min:0',
        ]);

        $req = $action->execute($booking, $data['new_customer_id'], (float) ($data['admin_fee'] ?? 0));
        return back()->with('success', "Request GN #{$req->id} dibuat, menunggu approval.");
    }

    public function requestGu(Request $request, Booking $booking, RequestUnitChange $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.gu.request'), 403);

        $data = $request->validate([
            'new_unit_id' => 'required|integer|exists:units,id|different:'.$booking->unit_id,
            'admin_fee'   => 'nullable|numeric|min:0',
        ]);

        $req = $action->execute($booking, $data['new_unit_id'], (float) ($data['admin_fee'] ?? 0));
        return back()->with('success', "Request GU #{$req->id} dibuat, menunggu approval.");
    }

    public function requestReschedule(Request $request, Booking $booking, RequestReschedule $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.reschedule.request'), 403);

        $data = $request->validate([
            'proposed'           => 'required|array|min:1',
            'proposed.*.id'      => 'required|integer|exists:payment_schedules,id',
            'proposed.*.new_due_date' => 'required|date|after:today',
            'admin_fee'          => 'nullable|numeric|min:0',
        ]);

        $req = $action->execute($booking, $data['proposed'], (float) ($data['admin_fee'] ?? 0));
        return back()->with('success', "Reschedule #{$req->id} dibuat, menunggu approval.");
    }
}
