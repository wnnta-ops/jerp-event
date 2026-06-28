<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GeneralLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year   = (int) $request->integer('year', (int) now()->year);
        $source = trim((string) $request->string('source', ''));

        $periodStatus = BsFiscalPeriod::where('year', $year)->pluck('status', 'month');

        // Hitung jurnal per bulan × status (Baru/Final/Approved), seperti board eProperty.
        $rows = BsJournal::where('period_year', $year)
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->selectRaw('period_month, status, count(*) as c, COALESCE(SUM(total_debit),0) as d')
            ->groupBy('period_month', 'status')->get();

        $byMonth = [];
        foreach ($rows as $r) {
            $m = (int) $r->period_month;
            $byMonth[$m] ??= ['baru' => 0, 'final' => 0, 'approved' => 0, 'total' => 0, 'debit' => 0.0];
            $key = match ($r->status) { 'final' => 'final', 'posted' => 'approved', default => 'baru' };
            $byMonth[$m][$key] += (int) $r->c;
            $byMonth[$m]['total'] += (int) $r->c;
            $byMonth[$m]['debit'] += (float) $r->d;
        }

        $periods = collect(range(1, 12))->map(function ($m) use ($byMonth, $periodStatus) {
            $b = $byMonth[$m] ?? ['baru' => 0, 'final' => 0, 'approved' => 0, 'total' => 0, 'debit' => 0.0];
            $isClosed = ($periodStatus[$m] ?? 'open') === 'closed';

            return [
                'month'    => $m,
                'baru'     => $b['baru'],
                'final'    => $b['final'],
                'approved' => $b['approved'],
                'closed'   => $isClosed ? $b['total'] : 0,
                'isClosed' => $isClosed,
                'total'    => $b['total'],
                'debit'    => $b['debit'],
                'status'   => $periodStatus[$m] ?? 'open',
            ];
        });

        $company = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');

        return Inertia::render('BusinessSupport/Gl/Index', [
            'year'      => $year,
            'source'    => $source,
            'sources'   => BsJournal::SOURCES,
            'companies' => [['id' => tenant_id(), 'name' => $company]],
            'periods'   => $periods,
            'current'   => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'totals'    => [
                'journals' => BsJournal::count(),
                'accounts' => \App\Domain\BusinessSupport\Models\BsAccount::count(),
            ],
        ]);
    }

    /** Print-Out: hub pemilihan laporan GL untuk dicetak. */
    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/PrintHub');
    }

    public function closePeriod(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);

        BsFiscalPeriod::updateOrCreate(
            ['tenant_id' => tenant_id(), 'year' => $v['year'], 'month' => $v['month']],
            ['status' => 'closed', 'closed_at' => now()],
        );

        return back()->with('success', "Periode {$v['year']}/{$v['month']} ditutup (CLOSED).");
    }

    public function openPeriod(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);

        BsFiscalPeriod::updateOrCreate(
            ['tenant_id' => tenant_id(), 'year' => $v['year'], 'month' => $v['month']],
            ['status' => 'open', 'closed_at' => null],
        );

        return back()->with('success', "Periode {$v['year']}/{$v['month']} dibuka kembali (OPEN).");
    }
}
