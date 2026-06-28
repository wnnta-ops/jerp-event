<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Cashback\Models\Cashback;
use App\Domain\Finance\Memo\Actions\CreateMemo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashbackController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.cashback.view'), 403);

        $rows = Cashback::query()->with(['booking:id,booking_number,customer_id'])->latest()->paginate(50);
        return Inertia::render('Finance/Cashback/Index', ['rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.cashback.request'), 403);

        $data = $request->validate([
            'booking_id' => 'required|integer|exists:bookings,id',
            'amount'     => 'required|numeric|min:1',
            'reason'     => 'required|string|max:500',
        ]);

        Cashback::create($data + ['status' => 'pending']);
        return back()->with('success', 'Permintaan cashback dibuat.');
    }

    public function approve(Cashback $cashback): RedirectResponse
    {
        abort_unless(auth()->user()?->can('finance.cashback.approve'), 403);

        $cashback->update(['status' => 'approved']);
        return back()->with('success', 'Cashback approved.');
    }

    public function pay(Cashback $cashback, CreateMemo $createMemo): RedirectResponse
    {
        abort_unless(auth()->user()?->can('finance.cashback.pay'), 403);

        if ($cashback->status !== 'approved') {
            return back()->with('error', 'Cashback harus approved dulu.');
        }

        $memo = $createMemo->execute([
            'type'           => 'outgoing',
            'memo_date'      => now()->toDateString(),
            'amount'         => $cashback->amount,
            'description'    => "Cashback booking {$cashback->booking?->booking_number}: {$cashback->reason}",
            'reference_type' => 'cashback',
            'reference_id'   => $cashback->id,
            'status'         => 'approved',
        ]);

        $cashback->update(['status' => 'paid', 'paid_at' => now()->toDateString()]);
        return back()->with('success', "Cashback dibayar via memo {$memo->memo_number}.");
    }
}
