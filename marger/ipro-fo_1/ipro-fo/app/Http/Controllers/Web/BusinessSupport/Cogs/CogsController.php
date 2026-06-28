<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogsBudget;
use App\Domain\BusinessSupport\Models\BsCogsBudgetMutation;
use App\Domain\BusinessSupport\Models\BsCogsHpp;
use App\Domain\BusinessSupport\Models\BsCogsRecognition;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\CogsService;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CogsController extends Controller
{
    /** Hub modul COGS (mirror menu eProperty BO /COGS/). */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Hub', [
            'summary' => [
                'budget'      => (float) BsCogsBudget::sum('amount'),
                'hpp_recognized' => (float) BsCogsHpp::sum('hpp_recognized'),
                'revenue_recognized' => (float) BsCogsHpp::sum('revenue_recognized'),
                'hpp_units'   => BsCogsHpp::distinct('unit_id')->count('unit_id'),
                'mut_pending' => BsCogsBudgetMutation::where('status', 'baru')->count(),
                'recognitions' => BsCogsRecognition::count(),
            ],
        ]);
    }

    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/PrintHub');
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        $rows = BsCogsRecognition::query()
            ->with('inventoryAccount:id,code,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('cogs_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('recognition_date')->latest('id')
            ->paginate(50)->withQueryString();

        // Cost pool: saldo Persediaan/CIP (10104%) dari jurnal terposting.
        $inventoryAccounts = BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '10104%')->orderBy('code')->get(['id', 'code', 'name']);
        $net = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->whereIn('bs_journal_lines.account_id', $inventoryAccounts->pluck('id'))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as bal')
            ->groupBy('bs_journal_lines.account_id')->pluck('bal', 'account_id');

        $pool = $inventoryAccounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'balance' => (float) ($net[$a->id] ?? 0)])
            ->filter(fn ($r) => abs($r['balance']) > 0.001)->values();

        return Inertia::render('BusinessSupport/Cogs/Index', [
            'recognitions' => $rows,
            'filters'      => $request->only(['search']),
            'pool'         => $pool,
            'poolTotal'    => $pool->sum('balance'),
            'totalCogs'    => (float) BsCogsRecognition::sum('amount'),
            'inventoryAccounts' => $inventoryAccounts,
            'costCenters'  => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, CogsService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        $v = $request->validate([
            'recognition_date'     => ['required', 'date'],
            'reference'            => ['nullable', 'string', 'max:150'],
            'description'          => ['required', 'string', 'max:200'],
            'inventory_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'cost_center_id'       => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $rec = $svc->recognize($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.cogs.index')->with('success', "HPP {$rec->cogs_number} diakui — jurnal otomatis (Dr Beban Pokok / Cr Persediaan).");
    }

    /** Generate HPP RealCost — hitung biaya riil per unit dari pool CIP, alokasikan ke unit. */
    public function realCost(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        $projectId = $request->integer('project_id') ?: null;
        $cip       = $request->integer('cip_account_id') ?: null;
        $basis     = in_array($request->input('basis'), ['equal', 'price'], true) ? $request->input('basis') : 'equal';
        $asOf      = $request->date('as_of')?->toDateString() ?? now()->toDateString();

        $result = ($projectId && $cip) ? $this->allocate($projectId, $cip, $basis, $asOf) : null;

        return Inertia::render('BusinessSupport/Cogs/RealCost', [
            'projects'    => Project::orderBy('name')->get(['id', 'name']),
            'cipAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '10104%')->orderBy('code')->get(['id', 'code', 'name']),
            'filters'     => ['project_id' => $projectId, 'cip_account_id' => $cip, 'basis' => $basis, 'as_of' => $asOf],
            'result'      => $result,
        ]);
    }

    /** Posting HPP RealCost — akui HPP unit terjual pada biaya riil teralokasi. */
    public function postRealCost(Request $request, CogsService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        $v = $request->validate([
            'project_id'       => ['required', 'integer', 'exists:projects,id'],
            'cip_account_id'   => ['required', 'integer', 'exists:bs_accounts,id'],
            'basis'            => ['required', 'in:equal,price'],
            'recognition_date' => ['required', 'date'],
            'unit_ids'         => ['required', 'array', 'min:1'],
            'unit_ids.*'       => ['integer'],
        ]);

        $alloc = $this->allocate($v['project_id'], $v['cip_account_id'], $v['basis'], $v['recognition_date']);
        $byId  = collect($alloc['units'])->keyBy('unit_id');

        $posted = 0;
        try {
            foreach ($v['unit_ids'] as $uid) {
                $row = $byId->get($uid);
                if (! $row || ! $row['eligible'] || $row['alloc'] <= 0.009) {
                    continue;
                }
                $svc->recognize([
                    'recognition_date'     => $v['recognition_date'],
                    'reference'            => $row['code'],
                    'description'          => 'HPP RealCost — Unit '.$row['code'],
                    'inventory_account_id' => $v['cip_account_id'],
                    'amount'               => $row['alloc'],
                ], $request->user()->id);
                $posted++;
            }
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.cogs.realcost', ['project_id' => $v['project_id'], 'cip_account_id' => $v['cip_account_id'], 'basis' => $v['basis']])
            ->with($posted ? 'success' : 'error', $posted ? "{$posted} unit diposting HPP RealCost (Dr Beban Pokok / Cr CIP)." : 'Tidak ada unit yang memenuhi syarat untuk diposting.');
    }

    /**
     * Alokasi pool CIP ke unit per proyek.
     * @return array{pool:float, total_basis:float, units:array<int,array<string,mixed>>, eligible_total:float, sold_count:int, recognized_count:int}
     */
    private function allocate(int $projectId, int $cipAccountId, string $basis, string $asOf): array
    {
        $pool = (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->where('bs_journal_lines.account_id', $cipAccountId)
            ->whereDate('bs_journals.journal_date', '<=', $asOf)
            ->sum(DB::raw('bs_journal_lines.debit - bs_journal_lines.credit'));
        $pool = round($pool, 2);

        $units    = Unit::where('project_id', $projectId)->orderBy('unit_code')->get(['id', 'unit_code', 'base_price', 'current_price']);
        $soldIds  = Booking::whereIn('unit_id', $units->pluck('id'))->distinct()->pluck('unit_id')->all();
        $recognized = BsCogsRecognition::whereIn('reference', $units->pluck('unit_code'))->pluck('reference')->all();

        $basisVal = fn ($u) => $basis === 'price' ? (float) ($u->current_price ?: $u->base_price) : 1.0;
        $totalBasis = (float) $units->sum(fn ($u) => $basisVal($u));

        $eligibleTotal = 0.0;
        $rows = $units->map(function ($u) use ($pool, $basisVal, $totalBasis, $soldIds, $recognized, &$eligibleTotal) {
            $bv    = $basisVal($u);
            $alloc = $totalBasis > 0 ? round($pool * $bv / $totalBasis, 2) : 0.0;
            $sold  = in_array($u->id, $soldIds, true);
            $rec   = in_array($u->unit_code, $recognized, true);
            $eligible = $sold && ! $rec;
            if ($eligible) {
                $eligibleTotal += $alloc;
            }

            return ['unit_id' => $u->id, 'code' => $u->unit_code, 'basis' => $bv, 'alloc' => $alloc, 'sold' => $sold, 'recognized' => $rec, 'eligible' => $eligible];
        })->values()->all();

        return [
            'pool' => $pool, 'total_basis' => $totalBasis, 'units' => $rows,
            'eligible_total' => round($eligibleTotal, 2),
            'sold_count' => count($soldIds), 'recognized_count' => count($recognized),
        ];
    }
}
