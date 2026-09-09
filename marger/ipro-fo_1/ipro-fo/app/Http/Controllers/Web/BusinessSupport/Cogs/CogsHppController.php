<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsCogsHpp;
use App\Domain\BusinessSupport\Models\BsCogsLocation;
use App\Domain\BusinessSupport\Services\CogsService;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Domain\BusinessSupport\Models\BsBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** HPP RealCost (POC) — master, Generate, Posting Jurnal HPP Persediaan, Posting Jurnal HPP. */
class CogsHppController extends Controller
{
    private function projects(): array { return Project::orderBy('name')->get(['id', 'name'])->toArray(); }
    private function locations(): array { return BsCogsLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray(); }

    /** Master HPP RealCost. */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.hpp.view'), 403);

        $rows = BsCogsHpp::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('cogs_id', 'like', "%{$s}%")->orWhere('unit_code', 'like', "%{$s}%")))
            ->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Cogs/Hpp/Index', [
            'rows'    => $rows,
            'filters' => $request->only('search'),
            'statuses' => BsCogsHpp::STATUSES,
        ]);
    }

    /** Generate HPP RealCost — form + jalankan. */
    public function generate(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.hpp.view'), 403);

        return Inertia::render('BusinessSupport/Cogs/Hpp/Generate', [
            'projects'  => $this->projects(),
            'locations' => $this->locations(),
            'months'    => BsBudget::MONTHS,
        ]);
    }

    public function runGenerate(Request $request, CogsService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.hpp.generate'), 403);
        $v = $request->validate([
            'project_id'       => ['required', 'integer'],
            'location_id'      => ['nullable', 'integer'],
            'year'             => ['required', 'integer', 'min:2000', 'max:2100'],
            'month'            => ['required', 'integer', 'min:1', 'max:12'],
            'default_progress' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        try {
            $res = $svc->generateHpp($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('bs.cogs.hpp.index')->with($res['generated'] ? 'success' : 'error', $res['generated'] ? "{$res['generated']} unit ter-generate HPP RealCost." : 'Tidak ada unit terjual baru untuk periode tsb.');
    }

    /** Posting Jurnal HPP Persediaan — pilih HPP status generated. */
    public function postPersediaanForm(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.hpp.view'), 403);

        $rows = BsCogsHpp::where('status', 'generated')->latest('id')->limit(500)->get()
            ->map(fn ($h) => ['id' => $h->id, 'cogs_id' => $h->cogs_id, 'unit_code' => $h->unit_code, 'period' => $h->period_month.'/'.$h->period_year, 'total_hpp' => (float) $h->total_hpp])->values();

        return Inertia::render('BusinessSupport/Cogs/Hpp/PostPersediaan', [
            'rows' => $rows, 'summary' => ['count' => $rows->count(), 'total' => $rows->sum('total_hpp')],
        ]);
    }

    public function postPersediaan(Request $request, CogsService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.hpp.post-persediaan'), 403);
        $v = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'post_date' => ['required', 'date']]);
        $ok = 0;
        $err = null;
        foreach (BsCogsHpp::whereIn('id', $v['ids'])->where('status', 'generated')->get() as $h) {
            try {
                $svc->postPersediaan($h, $v['post_date'], $request->user()->id);
                $ok++;
            } catch (BusinessRuleException $e) {
                $err = $e->getMessage();
            }
        }

        return back()->with($ok ? 'success' : 'error', $ok ? "$ok jurnal HPP Persediaan diposting (Dr Persediaan Unit / Cr CIP)." : ($err ?? 'Tidak ada yang diposting.'));
    }

    /** Posting Jurnal HPP — pilih HPP status posted_persediaan dengan HPP diakui > 0. */
    public function postHppForm(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.hpp.view'), 403);

        $rows = BsCogsHpp::whereIn('status', ['posted_persediaan', 'generated'])->where('hpp_recognized', '>', 0)->latest('id')->limit(500)->get()
            ->map(fn ($h) => ['id' => $h->id, 'cogs_id' => $h->cogs_id, 'unit_code' => $h->unit_code, 'period' => $h->period_month.'/'.$h->period_year, 'progress' => (float) $h->progress_pct, 'hpp_recognized' => (float) $h->hpp_recognized, 'status' => $h->status])->values();

        return Inertia::render('BusinessSupport/Cogs/Hpp/PostHpp', [
            'rows' => $rows, 'summary' => ['count' => $rows->count(), 'total' => $rows->sum('hpp_recognized')],
        ]);
    }

    public function postHpp(Request $request, CogsService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.hpp.post-hpp'), 403);
        $v = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'post_date' => ['required', 'date']]);
        $ok = 0;
        $err = null;
        foreach (BsCogsHpp::whereIn('id', $v['ids'])->where('status', '!=', 'posted_hpp')->get() as $h) {
            try {
                $svc->postHpp($h, $v['post_date'], $request->user()->id);
                $ok++;
            } catch (BusinessRuleException $e) {
                $err = $e->getMessage();
            }
        }

        return back()->with($ok ? 'success' : 'error', $ok ? "$ok jurnal HPP diposting (Dr Beban Pokok / Cr Persediaan Unit)." : ($err ?? 'Tidak ada yang diposting.'));
    }
}
