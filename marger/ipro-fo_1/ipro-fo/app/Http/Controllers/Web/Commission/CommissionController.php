<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Commission;

use App\Domain\Commission\Actions\ApproveCommissionPayout;
use App\Domain\Commission\Actions\PayCommission;
use App\Domain\Commission\Models\Commission;
use App\Domain\Commission\Models\CommissionLine;
use App\Domain\Commission\Models\CommissionPayout;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('commission.view'), 403);

        $status = $request->input('status');
        $rows = Commission::query()
            ->with(['booking:id,booking_number', 'lines.agent:id,code,name'])
            ->when($status, fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('status', $v)))
            ->latest()
            ->paginate(50)->withQueryString();

        return Inertia::render('Commission/Index', [
            'commissions'  => $rows,
            'filters'      => $request->only('status'),
            // Status baris komisi untuk filter (mis. "Sudah di-request").
            'lineStatuses' => [
                'scheduled' => 'Belum di-request',
                'ready'     => 'Sudah di-request',
                'approved'  => 'Disetujui',
                'paid'      => 'Dibayar',
            ],
        ]);
    }

    public function requestPayout(Request $request, CommissionLine $line): RedirectResponse
    {
        abort_unless($request->user()?->can('commission.payout.request'), 403);

        if ($line->status !== 'scheduled') {
            return back()->with('error', 'Line bukan scheduled.');
        }

        CommissionPayout::create([
            'commission_line_id' => $line->id,
            'amount'             => $line->amount,
            'status'             => 'ready',
        ]);
        $line->update(['status' => 'ready']);

        return back()->with('success', 'Permintaan payout dibuat.');
    }

    public function approvePayout(CommissionPayout $payout, ApproveCommissionPayout $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('commission.payout.approve'), 403);

        $action->execute($payout);
        return back()->with('success', 'Payout di-approve + memo dibuat.');
    }

    public function pay(CommissionPayout $payout, PayCommission $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('commission.payout.execute'), 403);

        $action->execute($payout);
        return back()->with('success', 'Payout berhasil dibayarkan.');
    }
}
