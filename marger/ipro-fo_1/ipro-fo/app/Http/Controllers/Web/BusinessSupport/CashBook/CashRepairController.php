<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash Book → Repair Data: hitung ulang nilai transaksi kas (amount) dari rinciannya,
 * dan deteksi transaksi tanpa jurnal / baris yatim.
 */
class CashRepairController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.repair.view'), 403);

        return Inertia::render('BusinessSupport/CashBook/Repair', [
            'diagnostics' => $this->diagnose(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        $res = DB::transaction(fn () => $this->repair());

        return back()->with('success', "Repair Cash Book selesai — {$res['amount']} nilai transaksi dikoreksi.");
    }

    /** @return \Illuminate\Support\Collection<int,object> Transaksi kas + total rinciannya. */
    private function rows()
    {
        $tid = tenant_id();

        return DB::table('bs_cash_transactions as t')
            ->whereNull('t.deleted_at')->when($tid, fn ($q) => $q->where('t.tenant_id', $tid))
            ->selectRaw('t.id, t.amount, t.journal_id, COALESCE((SELECT SUM(l.amount) FROM bs_cash_transaction_lines l WHERE l.cash_transaction_id = t.id),0) sl')
            ->get();
    }

    /** @return array<string,int> */
    private function diagnose(): array
    {
        $amountDrift = 0;
        $noJournal = 0;
        foreach ($this->rows() as $r) {
            if (abs((float) $r->amount - round((float) $r->sl, 2)) > 0.01) {
                $amountDrift++;
            }
            if (! $r->journal_id) {
                $noJournal++;
            }
        }

        $orphan = DB::table('bs_cash_transaction_lines as l')
            ->leftJoin('bs_cash_transactions as t', 't.id', '=', 'l.cash_transaction_id')
            ->where(fn ($q) => $q->whereNull('t.id')->orWhereNotNull('t.deleted_at'))
            ->count();

        return ['amount_drift' => $amountDrift, 'no_journal' => $noJournal, 'orphan_lines' => $orphan];
    }

    /** @return array<string,int> */
    private function repair(): array
    {
        $fixed = 0;
        foreach ($this->rows() as $r) {
            $sl = round((float) $r->sl, 2);
            if ($sl > 0 && abs((float) $r->amount - $sl) > 0.01) {
                DB::table('bs_cash_transactions')->where('id', $r->id)->update(['amount' => $sl]);
                $fixed++;
            }
        }

        return ['amount' => $fixed];
    }
}
