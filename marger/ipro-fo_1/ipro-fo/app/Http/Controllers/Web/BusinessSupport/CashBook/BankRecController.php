<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBankRecLine;
use App\Domain\BusinessSupport\Models\BsBankReconciliation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rekonsiliasi Bank — cocokkan saldo buku (GL kas/bank) dengan rekening koran.
 *   Saldo Koran + Setoran dalam perjalanan − Pembayaran beredar = Saldo Buku (jika cocok → reconciled).
 */
class BankRecController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/BankRec/Index', [
            'reconciliations' => BsBankReconciliation::with('account:id,code,name')->latest('statement_date')->latest('id')->paginate(50)->withQueryString(),
            'accounts' => $this->bankAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $v = $request->validate([
            'account_id'        => ['required', 'integer', 'exists:bs_accounts,id'],
            'statement_date'    => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes'             => ['nullable', 'string', 'max:255'],
        ]);

        $rec = DB::transaction(function () use ($v, $request): BsBankReconciliation {
            // Saldo buku GL akun s/d tanggal koran.
            $book = $this->bookBalance((int) $v['account_id'], $v['statement_date']);

            $rec = BsBankReconciliation::create([
                'account_id' => $v['account_id'], 'statement_date' => $v['statement_date'],
                'statement_balance' => $v['statement_balance'], 'book_balance' => $book,
                'status' => 'open', 'notes' => $v['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);

            // Snapshot seluruh mutasi GL kas/bank s/d tanggal koran.
            $movements = DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.status', 'posted')->where('jl.account_id', $v['account_id'])
                ->whereDate('j.journal_date', '<=', $v['statement_date'])
                ->where(fn ($q) => $q->where('jl.debit', '>', 0)->orWhere('jl.credit', '>', 0))
                ->orderBy('j.journal_date')->orderBy('jl.id')
                ->get(['jl.id as jlid', 'jl.journal_id', 'j.journal_date', 'jl.description', 'jl.debit', 'jl.credit']);

            foreach ($movements as $m) {
                $rec->lines()->create([
                    'journal_line_id' => $m->jlid, 'journal_id' => $m->journal_id, 'tx_date' => $m->journal_date,
                    'description' => $m->description, 'debit' => $m->debit, 'credit' => $m->credit, 'is_cleared' => false,
                ]);
            }

            return $rec;
        });

        return redirect()->route('bs.bankrec.show', $rec)->with('success', 'Rekonsiliasi dibuat — tandai transaksi yang sudah muncul di koran.');
    }

    public function show(Request $request, BsBankReconciliation $reconciliation): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $reconciliation->load('account:id,code,name');
        $lines = $reconciliation->lines()->orderBy('tx_date')->orderBy('id')->get();

        $unclearedDeposits = round((float) $lines->where('is_cleared', false)->sum('debit'), 2);
        $outstandingPayments = round((float) $lines->where('is_cleared', false)->sum('credit'), 2);
        $book = (float) $reconciliation->book_balance;
        $statement = (float) $reconciliation->statement_balance;
        $adjusted = round($statement + $unclearedDeposits - $outstandingPayments, 2);
        $difference = round($book - $adjusted, 2);

        return Inertia::render('BusinessSupport/CashBook/BankRec/Show', [
            'rec'     => $reconciliation,
            'lines'   => $lines,
            'summary' => [
                'book' => round($book, 2), 'statement' => round($statement, 2),
                'deposits_in_transit' => $unclearedDeposits, 'outstanding_payments' => $outstandingPayments,
                'adjusted' => $adjusted, 'difference' => $difference, 'balanced' => abs($difference) < 0.01,
                'cleared_count' => $lines->where('is_cleared', true)->count(), 'total_count' => $lines->count(),
            ],
        ]);
    }

    public function toggleLine(Request $request, BsBankReconciliation $reconciliation, BsBankRecLine $line): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        abort_unless((int) $line->reconciliation_id === (int) $reconciliation->id, 404);
        abort_if($reconciliation->status === 'reconciled', 422, 'Rekonsiliasi sudah final.');

        $line->update(['is_cleared' => ! $line->is_cleared]);

        return back(303);
    }

    public function finalize(Request $request, BsBankReconciliation $reconciliation): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $reconciliation->update(['status' => 'reconciled', 'reconciled_at' => now()->toDateString()]);

        return redirect()->route('bs.bankrec.show', $reconciliation)->with('success', 'Rekonsiliasi difinalisasi.');
    }

    /** @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function bankAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'book_balance' => $this->bookBalance($a->id, now()->toDateString())]);
    }

    private function bookBalance(int $accountId, string $date): float
    {
        $r = DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $accountId)
            ->whereDate('bs_journals.journal_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) bal')->first();

        return round((float) $r->bal, 2);
    }
}
