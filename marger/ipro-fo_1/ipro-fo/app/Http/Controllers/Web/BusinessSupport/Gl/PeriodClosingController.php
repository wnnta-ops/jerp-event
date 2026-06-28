<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsOpeningConfirmation;
use App\Domain\BusinessSupport\Services\PeriodClosingService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tutup Buku — kunci periode bulanan & jurnal penutup tahunan (L/R → Laba Ditahan).
 */
class PeriodClosingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year = (int) ($request->integer('year') ?: now()->year);

        $statuses = BsFiscalPeriod::where('year', $year)->pluck('status', 'month');
        $closedAt = BsFiscalPeriod::where('year', $year)->pluck('closed_at', 'month');

        // P/L per bulan (pendapatan − beban) dari jurnal posted.
        $rows = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->where('j.period_year', $year)
            ->whereIn('a.type', ['income', 'expense'])
            ->groupBy('j.period_month', 'a.type')
            ->selectRaw('j.period_month month, a.type, COALESCE(SUM(jl.credit)-SUM(jl.debit),0) net')
            ->get();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $income  = (float) ($rows->where('month', $m)->where('type', 'income')->first()->net ?? 0);
            $expense = -1 * (float) ($rows->where('month', $m)->where('type', 'expense')->first()->net ?? 0); // beban: debit-credit
            $months[] = [
                'month' => $m, 'income' => round($income, 2), 'expense' => round($expense, 2),
                'profit' => round($income - $expense, 2),
                'status' => $statuses[$m] ?? 'open', 'closed_at' => $closedAt[$m] ?? null,
            ];
        }

        $ytdIncome  = round(array_sum(array_column($months, 'income')), 2);
        $ytdExpense = round(array_sum(array_column($months, 'expense')), 2);
        $closingJournal = BsJournal::where('reference', "CLOSING/{$year}")->where('source', 'closing')->first();

        return Inertia::render('BusinessSupport/Gl/TutupBuku', [
            'year'    => $year,
            'months'  => $months,
            'ytd'     => ['income' => $ytdIncome, 'expense' => $ytdExpense, 'profit' => round($ytdIncome - $ytdExpense, 2)],
            'yearClosed' => (bool) $closingJournal,
            'closingJournalId' => $closingJournal?->id,
            'allClosed'  => collect($months)->every(fn ($m) => $m['status'] === 'closed'),
        ]);
    }

    /**
     * Neraca Awal tahun berjalan — saldo carry-forward akun permanen (aset/kewajiban/ekuitas)
     * per 1 Januari {year} = saldo kumulatif s/d 31 Des {year-1}. Termasuk Laba Ditahan setelah tutup buku.
     */
    public function neracaAwal(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year   = (int) ($request->integer('year') ?: now()->year);
        $cutoff = sprintf('%04d-01-01', $year); // mutasi < tanggal ini = s/d 31 Des tahun sebelumnya

        $balances = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<', $cutoff)
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->get();

        $section = fn (string $type, bool $debitNormal) => $balances->where('type', $type)
            ->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'amount' => round($debitNormal ? (float) $x->net : -1 * (float) $x->net, 2)])
            ->filter(fn ($x) => abs($x['amount']) > 0.001)->sortBy('code')->values();

        $assets = $section('asset', true);
        $liabilities = $section('liability', false);
        $equity = $section('equity', false);
        // Laba/(rugi) yang belum dijurnal-tutup (akun nominal s/d cutoff). 0 bila tahun lalu sudah ditutup.
        $unclosed = round((float) $balances->whereIn('type', ['income', 'expense'])->sum('net') * -1, 2); // (credit-debit)

        $totalAssets = round($assets->sum('amount'), 2);
        $totalLiab   = round($liabilities->sum('amount'), 2);
        $totalEquity = round($equity->sum('amount') + $unclosed, 2);
        $priorClosed = BsJournal::where('reference', 'CLOSING/'.($year - 1))->where('source', 'closing')->exists();

        return Inertia::render('BusinessSupport/Gl/NeracaAwal', [
            'year' => $year,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'unclosed' => $unclosed,
            'totals' => [
                'assets' => $totalAssets, 'liabilities' => $totalLiab, 'equity' => $totalEquity,
                'liab_equity' => round($totalLiab + $totalEquity, 2),
                'balanced' => abs($totalAssets - ($totalLiab + $totalEquity)) < 0.01,
            ],
            'priorClosed' => $priorClosed,
        ]);
    }

    /** Jurnal Saldo Awal (preview) — Neraca Awal dalam bentuk jurnal Dr/Cr per akun. Tidak di-posting. */
    public function saldoAwalJurnal(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);

        return Inertia::render('BusinessSupport/Gl/SaldoAwalJurnal', [
            'year'   => $year,
            'lines'  => $lines,
            'totals' => [
                'debit'  => round(array_sum(array_column($lines, 'debit')), 2),
                'credit' => round(array_sum(array_column($lines, 'credit')), 2),
            ],
            'priorClosed' => BsJournal::where('reference', 'CLOSING/'.($year - 1))->where('source', 'closing')->exists(),
        ]);
    }

    public function saldoAwalCsv(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);

        return response()->streamDownload(function () use ($lines, $year): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['TANGGAL', 'KODE_AKUN', 'NAMA_AKUN', 'DEBIT', 'KREDIT', 'KETERANGAN']);
            foreach ($lines as $l) {
                fputcsv($out, [sprintf('%04d-01-01', $year), $l['code'], $l['name'], $l['debit'], $l['credit'], 'Saldo Awal '.$year]);
            }
            fclose($out);
        }, "jurnal-saldo-awal-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Baris jurnal saldo awal tahun {year} = saldo akun permanen s/d 31 Des {year-1} + L/R belum ditutup → Laba Ditahan. @return array<int,array<string,mixed>> */
    private function openingJournalLines(int $year): array
    {
        $cutoff = sprintf('%04d-01-01', $year);
        $rows = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<', $cutoff)
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->orderBy('a.code')->get();

        $lines = [];
        $unclosed = 0.0;
        foreach ($rows as $r) {
            if (in_array($r->type, ['asset', 'liability', 'equity'], true)) {
                $net = round((float) $r->net, 2);
                if (abs($net) < 0.01) {
                    continue;
                }
                $lines[] = ['code' => $r->code, 'name' => $r->name, 'debit' => $net > 0 ? $net : 0.0, 'credit' => $net < 0 ? round(-$net, 2) : 0.0];
            } elseif (in_array($r->type, ['income', 'expense'], true)) {
                $unclosed += (float) $r->net; // debit−credit; laba → negatif
            }
        }

        // L/R yang belum dijurnal-tutup → ke Laba Ditahan (agar balance). 0 bila tahun lalu sudah ditutup.
        $profit = round(-1 * $unclosed, 2);
        if (abs($profit) >= 0.01) {
            $ld = BsAccount::where('code', config('business_support.gl_accounts.retained_earnings'))->first();
            $lines[] = [
                'code' => $ld?->code ?? '80101010101', 'name' => ($ld?->name ?? 'Laba Ditahan').' (L/R berjalan belum ditutup)',
                'debit' => $profit < 0 ? round(-$profit, 2) : 0.0, 'credit' => $profit > 0 ? $profit : 0.0,
            ];
        }

        return $lines;
    }

    public function close(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $svc->closePeriod($v['year'], $v['month']);

        return back()->with('success', "Periode {$v['month']}/{$v['year']} ditutup (dikunci).");
    }

    public function reopen(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $svc->reopenPeriod($v['year'], $v['month']);

        return back()->with('success', "Periode {$v['month']}/{$v['year']} dibuka kembali.");
    }

    public function closeYear(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer']]);

        try {
            $journal = $svc->closeYear($v['year'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = $journal
            ? "Tutup Buku tahun {$v['year']} selesai — jurnal penutup #{$journal->id} terbentuk, 12 bulan dikunci."
            : "Tutup Buku tahun {$v['year']} — tidak ada saldo nominal untuk ditutup, 12 bulan dikunci.";

        return back()->with('success', $msg);
    }

    // ── Confirm Saldo Awal ────────────────────────────────────────────────

    /** Halaman konfirmasi saldo awal: tinjau jurnal saldo awal lalu kunci/konfirmasi. */
    public function confirmSaldoAwal(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);
        $debit  = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        return Inertia::render('BusinessSupport/Gl/ConfirmSaldoAwal', [
            'year'         => $year,
            'lines'        => $lines,
            'totals'       => ['debit' => $debit, 'credit' => $credit, 'balanced' => abs($debit - $credit) < 0.01],
            'confirmation' => BsOpeningConfirmation::where('year', $year)->first(),
        ]);
    }

    public function storeSaldoAwalConfirm(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer']]);
        $year  = (int) $v['year'];
        $lines = $this->openingJournalLines($year);
        $debit  = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        BsOpeningConfirmation::updateOrCreate(
            ['year' => $year],
            [
                'total_debit' => $debit, 'total_credit' => $credit, 'balanced' => abs($debit - $credit) < 0.01,
                'confirmed_by' => $request->user()->id, 'confirmed_at' => now(),
            ],
        );

        return back()->with('success', "Saldo Awal {$year} dikonfirmasi & dikunci.");
    }

    // ── Late Closing ──────────────────────────────────────────────────────

    /** Penutupan terlambat: tutup periode mundur yang sudah lewat jatuh tempo, dengan catatan. */
    public function lateClosing(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $year = (int) ($request->integer('year') ?: now()->year);
        $now  = now();
        $byMonth = BsFiscalPeriod::where('year', $year)->get()->keyBy('month');

        $periods = collect(range(1, 12))->map(function ($m) use ($year, $byMonth, $now) {
            $p   = $byMonth[$m] ?? null;
            $end = Carbon::create($year, $m, 1)->endOfMonth();

            return [
                'month'       => $m,
                'status'      => $p?->status ?? 'open',
                'closed_at'   => $p?->closed_at,
                'late_closed' => (bool) ($p?->late_closed),
                'late_note'   => $p?->late_note,
                'overdue'     => $now->greaterThan($end) && ($p?->status ?? 'open') !== 'closed',
            ];
        });

        return Inertia::render('BusinessSupport/Gl/LateClosing', [
            'year'    => $year,
            'periods' => $periods,
            'today'   => $now->toDateString(),
        ]);
    }

    public function lateClose(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'year'  => ['required', 'integer'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'note'  => ['nullable', 'string', 'max:255'],
        ]);

        $period = $svc->closePeriod($v['year'], $v['month']);
        $period->update(['late_closed' => true, 'late_note' => $v['note'] ?? null]);

        return back()->with('success', "Periode {$v['month']}/{$v['year']} ditutup (Late Closing).");
    }
}
