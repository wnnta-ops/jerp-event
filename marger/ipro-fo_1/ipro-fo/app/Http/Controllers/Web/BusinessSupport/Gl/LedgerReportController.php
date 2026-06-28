<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LedgerReportController extends Controller
{
    /** Buku Besar (General Ledger) per akun dengan saldo berjalan. */
    public function ledger(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $accountId = $request->integer('account_id');
        $from = $request->date('from');
        $to   = $request->date('to');

        $rows = collect();
        $opening = 0.0;
        $account = $accountId ? BsAccount::find($accountId) : null;

        if ($account) {
            // Saldo awal sebelum 'from'.
            if ($from) {
                $opening = (float) BsJournalLine::where('account_id', $accountId)
                    ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->whereDate('journal_date', '<', $from))
                    ->selectRaw('COALESCE(SUM(debit-credit),0) as b')->value('b');
            }
            $running = $opening;
            $rows = BsJournalLine::query()
                ->where('account_id', $accountId)
                ->whereHas('journal', function ($q) use ($from, $to) {
                    $q->where('status', 'posted');
                    if ($from) $q->whereDate('journal_date', '>=', $from);
                    if ($to) $q->whereDate('journal_date', '<=', $to);
                })
                ->with('journal:id,journal_number,journal_date,description')
                ->get()
                ->sortBy(fn ($l) => [$l->journal->journal_date, $l->id])
                ->values()
                ->map(function ($l) use (&$running) {
                    $running += (float) $l->debit - (float) $l->credit;
                    return [
                        'date' => $l->journal->journal_date?->format('Y-m-d'),
                        'number' => $l->journal->journal_number,
                        'description' => $l->description ?: $l->journal->description,
                        'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'balance' => $running,
                    ];
                });
        }

        return Inertia::render('BusinessSupport/Gl/Ledger', [
            'accounts' => BsAccount::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'account'  => $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] : null,
            'opening'  => $opening,
            'rows'     => $rows,
            'filters'  => ['account_id' => $accountId ?: '', 'from' => $request->input('from'), 'to' => $request->input('to')],
        ]);
    }

    /** Neraca Saldo (Trial Balance). */
    public function trialBalance(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $balances = $this->accountBalances($request->integer('year') ?: null);

        $rows = $balances->map(fn ($b) => [
            'code' => $b['code'], 'name' => $b['name'], 'type' => $b['type'],
            'debit'  => $b['net'] >= 0 ? $b['net'] : 0,
            'credit' => $b['net'] < 0 ? -$b['net'] : 0,
        ])->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)->values();

        return Inertia::render('BusinessSupport/Gl/TrialBalance', [
            'rows'         => $rows,
            'total_debit'  => $rows->sum('debit'),
            'total_credit' => $rows->sum('credit'),
            'year'         => $request->integer('year') ?: null,
        ]);
    }

    /** Neraca (Balance Sheet) + Laba Rugi (Income Statement). */
    public function financials(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year = $request->integer('year') ?: null;
        $b = $this->accountBalances($year);

        $byType = fn (string $type) => $b->where('type', $type)->map(fn ($x) => [
            'code' => $x['code'], 'name' => $x['name'],
            'amount' => in_array($type, ['asset', 'expense']) ? $x['net'] : -$x['net'], // tampilkan positif sesuai sifat
        ])->filter(fn ($x) => abs($x['amount']) > 0.001)->values();

        $income  = $byType('income');
        $expense = $byType('expense');
        $totalIncome = $income->sum('amount');
        $totalExpense = $expense->sum('amount');
        $netProfit = $totalIncome - $totalExpense;

        $assets = $byType('asset');
        $liabilities = $byType('liability');
        $equity = $byType('equity');
        $totalAssets = $assets->sum('amount');
        $totalLiab = $liabilities->sum('amount');
        $totalEquity = $equity->sum('amount');

        return Inertia::render('BusinessSupport/Gl/Financials', [
            'year' => $year,
            'incomeStatement' => [
                'income' => $income, 'expense' => $expense,
                'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net_profit' => $netProfit,
            ],
            'balanceSheet' => [
                'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
                'total_assets' => $totalAssets, 'total_liabilities' => $totalLiab,
                'total_equity' => $totalEquity, 'net_profit' => $netProfit,
                'total_liab_equity' => $totalLiab + $totalEquity + $netProfit,
            ],
        ]);
    }

    /** Saldo per akun = Σ(debit-credit). @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function accountBalances(?int $year)
    {
        $lines = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->when($year, fn ($q) => $q->where('bs_journals.period_year', $year))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as net')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('net', 'account_id');

        return BsAccount::whereIn('id', $lines->keys())->orderBy('code')->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'net' => (float) $lines[$a->id]]);
    }
}
