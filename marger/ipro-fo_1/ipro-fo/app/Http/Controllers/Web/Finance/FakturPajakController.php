<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\FakturPajak\Actions\CreateFakturPajak;
use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FakturPajakController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.fakturpajak.view'), 403);

        $rows = FakturPajak::query()->latest('issued_at')->paginate(50);
        return Inertia::render('Finance/FakturPajak/Index', ['rows' => $rows]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('finance.fakturpajak.create'), 403);

        return Inertia::render('Finance/FakturPajak/Create', [
            'customers' => Customer::select('id', 'code', 'full_name')->get(),
            'bookings'  => Booking::with('customer:id,full_name')->get(['id', 'booking_number', 'customer_id', 'total_price']),
            'ppn_pct'   => config('domain.tax.ppn_default_pct'),
        ]);
    }

    public function store(Request $request, CreateFakturPajak $action): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.fakturpajak.create'), 403);

        $data = $request->validate([
            'customer_id'        => 'required|integer|exists:customers,id',
            'booking_id'         => 'nullable|integer|exists:bookings,id',
            'issued_at'          => 'required|date',
            'ppn_pct'            => 'nullable|numeric|min:0|max:100',
            'items'              => 'required|array|min:1',
            'items.*.description'=> 'required|string|max:255',
            'items.*.qty'        => 'nullable|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total'      => 'required|numeric|min:0',
        ]);

        $fp = $action->execute($data, $data['items']);
        return redirect()->route('faktur-pajak.index')->with('success', "Faktur Pajak {$fp->fp_number} dibuat.");
    }
}
