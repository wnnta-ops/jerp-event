<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsCogsBudget;
use App\Domain\BusinessSupport\Models\BsCogsHpp;
use App\Domain\BusinessSupport\Models\BsCogsRealization;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** COGS Query / Laporan (6). */
class CogsQueryController extends Controller
{
    private function f(Request $request): array
    {
        return ['project_id' => $request->integer('project_id') ?: null, 'year' => (int) $request->integer('year', (int) now()->year)];
    }

    private function projects(): array { return Project::orderBy('name')->get(['id', 'name'])->toArray(); }

    /** Report Budget. */
    public function budget(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);
        $rows = BsCogsBudget::with(['category:id,code,name,component', 'location:id,name', 'account:id,code,name'])
            ->where('year', $f['year'])->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->orderBy('location_id')->orderBy('category_id')->get()
            ->map(fn ($b) => [
                'location' => $b->location?->name ?? 'Umum', 'category' => $b->category?->code.' '.$b->category?->name,
                'component' => $b->category?->component, 'account' => $b->account?->code, 'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Query/Budget', ['filters' => $f, 'projects' => $this->projects(), 'rows' => $rows, 'total' => $rows->sum('amount')]);
    }

    /** Report Realisasi Budget — budget vs realisasi per kategori. */
    public function realisasi(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);

        $budget = BsCogsBudget::with('category:id,code,name')->where('year', $f['year'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))->get()
            ->groupBy('category_id');
        $real = BsCogsRealization::where('year', $f['year'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))->get()
            ->groupBy('category_id');

        $catIds = $budget->keys()->merge($real->keys())->unique();
        $rows = $catIds->map(function ($cid) use ($budget, $real) {
            $b = $budget->get($cid);
            $cat = $b?->first()?->category;
            $bAmt = (float) ($b?->sum('amount') ?? 0);
            $rAmt = (float) ($real->get($cid)?->sum('amount') ?? 0);

            return ['category' => ($cat?->code.' '.$cat?->name) ?: '—', 'budget' => $bAmt, 'realisasi' => $rAmt, 'sisa' => round($bAmt - $rAmt, 2), 'pct' => $bAmt > 0 ? round($rAmt / $bAmt * 100, 1) : 0];
        })->values();

        return Inertia::render('BusinessSupport/Cogs/Query/Realisasi', [
            'filters' => $f, 'projects' => $this->projects(), 'rows' => $rows,
            'total' => ['budget' => $rows->sum('budget'), 'realisasi' => $rows->sum('realisasi'), 'sisa' => $rows->sum('sisa')],
        ]);
    }

    /** Report Realisasi Budget Detil. */
    public function realisasiDetil(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);
        $rows = BsCogsRealization::with(['category:id,code,name', 'location:id,name'])
            ->where('year', $f['year'])->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->orderByDesc('realization_date')->get()
            ->map(fn ($r) => [
                'doc_no' => $r->doc_no, 'date' => $r->realization_date?->format('Y-m-d'), 'type' => $r->type,
                'location' => $r->location?->name ?? '—', 'category' => $r->category?->code.' '.$r->category?->name,
                'amount' => (float) $r->amount, 'note' => $r->note,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Query/RealisasiDetil', ['filters' => $f, 'projects' => $this->projects(), 'rows' => $rows, 'total' => $rows->sum('amount')]);
    }

    /** Report Pengakuan HPP RealCost. */
    public function pengakuan(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);
        $rows = BsCogsHpp::where('period_year', $f['year'])->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->orderBy('period_month')->orderBy('unit_code')->get()
            ->map(fn ($h) => [
                'cogs_id' => $h->cogs_id, 'period' => $h->period_month.'/'.$h->period_year, 'unit' => $h->unit_code,
                'total_hpp' => (float) $h->total_hpp, 'progress' => (float) $h->progress_pct,
                'hpp_recognized' => (float) $h->hpp_recognized, 'revenue_recognized' => (float) $h->revenue_recognized, 'status' => $h->status,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Query/Pengakuan', [
            'filters' => $f, 'projects' => $this->projects(), 'rows' => $rows,
            'total' => ['hpp' => $rows->sum('hpp_recognized'), 'revenue' => $rows->sum('revenue_recognized')],
        ]);
    }

    /** Report Persediaan Unit RealCost — kapitalisasi (posted persediaan) − HPP diakui = saldo persediaan unit. */
    public function persediaanUnit(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);
        $rows = BsCogsHpp::when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->get()->groupBy('unit_id')->map(function ($grp) {
                $cap = (float) $grp->whereIn('status', ['posted_persediaan', 'posted_hpp'])->sum('total_hpp');
                $rec = (float) $grp->sum('hpp_accum');
                $h = $grp->first();

                return ['unit' => $h->unit_code, 'kapitalisasi' => $cap, 'hpp_diakui' => $rec, 'saldo' => round($cap - $rec, 2)];
            })->values();

        return Inertia::render('BusinessSupport/Cogs/Query/PersediaanUnit', [
            'filters' => $f, 'projects' => $this->projects(), 'rows' => $rows,
            'total' => ['kapitalisasi' => $rows->sum('kapitalisasi'), 'hpp_diakui' => $rows->sum('hpp_diakui'), 'saldo' => $rows->sum('saldo')],
        ]);
    }

    /** Report HPP Unit RealCost — akumulasi HPP & pendapatan per unit. */
    public function hppUnit(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $f = $this->f($request);
        $rows = BsCogsHpp::when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->get()->groupBy('unit_id')->map(function ($grp) {
                $h = $grp->sortByDesc('id')->first();

                return [
                    'unit' => $h->unit_code, 'nilai_kontrak' => (float) $h->nilai_kontrak,
                    'total_hpp' => (float) $h->total_hpp, 'progress' => (float) $h->progress_pct,
                    'revenue_accum' => (float) $grp->sum('revenue_recognized'), 'hpp_accum' => (float) $grp->sum('hpp_recognized'),
                ];
            })->values();

        return Inertia::render('BusinessSupport/Cogs/Query/HppUnit', [
            'filters' => $f, 'projects' => $this->projects(), 'rows' => $rows,
            'total' => ['revenue' => $rows->sum('revenue_accum'), 'hpp' => $rows->sum('hpp_accum')],
        ]);
    }
}
