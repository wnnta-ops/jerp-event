<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Memo\Actions\CreateMemo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.refund.request') || $request->user()?->can('finance.refund.approve'), 403);

        $rows = DB::table('refunds')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(50);

        return Inertia::render('Finance/Refund/Index', [
            'rows'    => $rows,
            'filters' => $request->only(['status']),
        ]);
    }

    public function approve(Request $request, int $refundId): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.refund.approve'), 403);

        DB::table('refunds')->where('id', $refundId)->update(['status' => 'approved', 'updated_at' => now()]);
        return back()->with('success', 'Refund approved.');
    }

    public function pay(Request $request, int $refundId, CreateMemo $createMemo): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.refund.pay'), 403);

        $refund = DB::table('refunds')->where('id', $refundId)->first();
        if (! $refund || $refund->status !== 'approved') {
            return back()->with('error', 'Refund harus approved dulu.');
        }

        $memo = $createMemo->execute([
            'type'           => 'outgoing',
            'memo_date'      => now()->toDateString(),
            'amount'         => $refund->amount,
            'description'    => "Refund {$refund->refund_number}",
            'reference_type' => $refund->refundable_type,
            'reference_id'   => $refund->refundable_id,
            'status'         => 'approved',
        ]);

        DB::table('refunds')->where('id', $refundId)->update([
            'status'   => 'paid',
            'paid_at'  => now()->toDateString(),
            'memo_id'  => $memo->id,
            'updated_at' => now(),
        ]);

        return back()->with('success', "Refund dibayar via memo {$memo->memo_number}.");
    }
}
