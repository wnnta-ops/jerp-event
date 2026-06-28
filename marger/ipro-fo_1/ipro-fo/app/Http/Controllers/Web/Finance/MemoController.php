<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Memo\Actions\CancelDpMemo;
use App\Domain\Finance\Memo\Actions\CreateMemo;
use App\Domain\Finance\Memo\Actions\GrantFreeDp;
use App\Domain\Finance\Memo\Actions\GrantSubsidiDp;
use App\Domain\Finance\Memo\Models\Memo;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\Memo\StoreMemoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemoController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.memo.view'), 403);

        $rows = Memo::query()
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('search'), fn ($q, $term) => $q->where('memo_number', 'like', "%{$term}%"))
            ->latest('memo_date')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Finance/Memo/Index', [
            'memos'   => $rows,
            'filters' => $request->only(['type', 'search']),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('finance.memo.create'), 403);

        return Inertia::render('Finance/Memo/Create', [
            'bank_accounts' => \DB::table('bank_accounts')->where('is_active', true)->get(['id', 'account_name', 'account_number', 'bank_name']),
        ]);
    }

    public function store(StoreMemoRequest $request, CreateMemo $action): RedirectResponse
    {
        $memo = $action->execute($request->validated());

        return redirect()->route('memos.index')
            ->with('success', "Memo {$memo->memo_number} berhasil dibuat.");
    }

    /**
     * Grant Free DP untuk booking — clear all unpaid DP schedules via Memo + Diskon allocation.
     */
    public function applyFreeDp(Request $request, Booking $booking, GrantFreeDp $action): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.memo.create'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $result = $action->execute($booking, $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            sprintf(
                'Free DP berhasil diberikan via memo %s (Rp %s, %d schedule).',
                $result['memo']->memo_number,
                number_format((float) $result['memo']->amount, 0, ',', '.'),
                $result['allocations']->count(),
            ),
        );
    }

    /**
     * Grant Subsidi DP — partial coverage with specified amount.
     */
    public function applySubsidiDp(Request $request, Booking $booking, GrantSubsidiDp $action): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.memo.create'), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $result = $action->execute($booking, (float) $data['amount'], $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            sprintf(
                'Subsidi DP Rp %s berhasil diberikan via memo %s.',
                number_format((float) $data['amount'], 0, ',', '.'),
                $result['memo']->memo_number,
            ),
        );
    }

    /**
     * Cancel Free DP / Subsidi DP memo.
     */
    public function cancel(Request $request, Memo $memo, CancelDpMemo $action): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.memo.create'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $action->execute($memo, $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Memo {$memo->memo_number} berhasil dibatalkan.");
    }
}
