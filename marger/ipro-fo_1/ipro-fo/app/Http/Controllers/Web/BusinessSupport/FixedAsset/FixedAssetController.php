<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\FixedAsset;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFixedAsset;
use App\Domain\BusinessSupport\Services\FixedAssetService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $rows = BsFixedAsset::query()
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('asset_number', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->latest('acquisition_date')->latest('id')
            ->paginate(50)->withQueryString();

        $rows->getCollection()->transform(fn ($a) => array_merge($a->toArray(), ['book_value' => $a->book_value, 'monthly_depreciation' => $a->monthly_depreciation]));

        return Inertia::render('BusinessSupport/FixedAsset/Index', [
            'assets'     => $rows,
            'categories' => BsFixedAsset::CATEGORIES,
            'filters'    => $request->only(['category', 'status', 'search']),
            'summary'    => [
                'cost'       => (float) BsFixedAsset::sum('acquisition_cost'),
                'accum'      => (float) BsFixedAsset::sum('accumulated_depreciation'),
                'book_value' => (float) BsFixedAsset::sum('acquisition_cost') - (float) BsFixedAsset::sum('accumulated_depreciation'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Form', [
            'categories'      => BsFixedAsset::CATEGORIES,
            'fundingAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '101010%')->orWhere('type', 'liability'))
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(Request $request, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'name'               => ['required', 'string', 'max:150'],
            'quantity'           => ['nullable', 'string', 'max:32'],
            'category'           => ['required', 'in:bangunan,kendaraan,inventaris,tanah'],
            'acquisition_date'   => ['required', 'date'],
            'acquisition_cost'   => ['required', 'numeric', 'min:0.01'],
            'residual_value'     => ['nullable', 'numeric', 'min:0', 'lt:acquisition_cost'],
            'useful_life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'funding_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'pic'                => ['nullable', 'string', 'max:100'],
            'location'           => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $asset = $svc->register($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $asset)->with('success', "Aset {$asset->asset_number} terdaftar.");
    }

    public function show(Request $request, BsFixedAsset $fixedAsset): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Show', [
            'asset'   => array_merge(
                $fixedAsset->load(['assetAccount:id,code,name', 'entries' => fn ($q) => $q->orderBy('period_year')->orderBy('period_month')])->toArray(),
                ['book_value' => $fixedAsset->book_value, 'monthly_depreciation' => $fixedAsset->monthly_depreciation, 'depreciable_base' => $fixedAsset->depreciable_base],
            ),
            'now'     => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'today'   => now()->toDateString(),
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function dispose(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'disposed_date' => ['required', 'date'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->dispose($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', "Aset {$fixedAsset->asset_number} dihapus — jurnal pelepasan terbentuk.");
    }

    public function sell(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'disposed_date'   => ['required', 'date'],
            'proceeds'        => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'note'            => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->sell($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', "Aset {$fixedAsset->asset_number} terjual — jurnal laba/rugi terbentuk.");
    }

    public function opening(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'as_of_date' => ['required', 'date'],
            'amount'     => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $svc->openingDepreciation($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', 'Saldo awal akumulasi penyusutan tercatat.');
    }

    public function depreciate(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $entry = $svc->depreciate($fixedAsset, $v['year'], $v['month'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with($entry ? 'success' : 'error', $entry
            ? 'Penyusutan '.$v['year'].'/'.$v['month'].' terposting (Dr Beban / Cr Akumulasi).'
            : 'Tidak ada penyusutan untuk periode itu (sudah disusut / belum perolehan / nilai habis).');
    }

    public function run(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Run', [
            'now'      => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'eligible' => BsFixedAsset::where('status', 'active')->where('category', '!=', 'tanah')->count(),
        ]);
    }

    public function runStore(Request $request, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $res = $svc->runBatch($v['year'], $v['month'], $request->user()->id);

        return redirect()->route('bs.fa.index')->with('success',
            "Penyusutan {$v['year']}/{$v['month']}: {$res['posted']} aset terposting (Rp ".number_format($res['total'], 0, ',', '.')."), {$res['skipped']} dilewati.");
    }

    /**
     * Report Summary Aset — jadwal penyusutan garis lurus (persis lampiran):
     * per kategori → per aset → per periode (tahunan lampau + bulanan tahun terpilih):
     * Jumlah Bulan, Beban Penyusutan, Akumulasi, Nilai Buku + subtotal per kategori & grand total.
     */
    public function summary(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $year   = (int) $request->integer('year', (int) now()->year);
        $assets = BsFixedAsset::whereIn('status', ['active', 'fully_depreciated'])
            ->orderBy('category')->orderBy('acquisition_date')->orderBy('id')->get();

        // Periode: tahunan dari tahun perolehan terlama (maks 6 th ke belakang) s/d year-1, lalu bulanan Jan-Des year.
        $minYear   = (int) ($assets->min(fn ($a) => (int) $a->acquisition_date->year) ?: $year);
        $startYear = max($minYear, $year - 6);
        $mn        = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $periods   = [];
        for ($y = $startYear; $y < $year; $y++) {
            $periods[] = ['key' => "y{$y}", 'label' => "Tahun {$y}", 'end' => Carbon::create($y, 12, 1)->endOfMonth()];
        }
        for ($m = 1; $m <= 12; $m++) {
            $periods[] = ['key' => "m{$year}-{$m}", 'label' => $mn[$m - 1]." {$year}", 'end' => Carbon::create($year, $m, 1)->endOfMonth()];
        }
        $pc = count($periods);

        // Akumulasi & jumlah bulan penyusutan tercatat sampai akhir suatu periode.
        $accumAt = function (BsFixedAsset $a, Carbon $end): array {
            $monthly = $a->monthly_depreciation;
            if ($monthly <= 0 || $a->acquisition_date->gt($end)) {
                return ['months' => 0, 'accum' => 0.0];
            }
            $months = ($end->year - $a->acquisition_date->year) * 12 + ($end->month - $a->acquisition_date->month) + 1;
            $months = max(0, min($months, (int) $a->useful_life_months));

            return ['months' => $months, 'accum' => min(round($months * $monthly, 2), $a->depreciable_base)];
        };
        $blank = fn () => array_fill(0, $pc, ['expense' => 0.0, 'accum' => 0.0, 'book' => 0.0]);
        $add   = function (array $agg, int $i, float $e, float $ac, float $bk): array {
            $agg[$i] = ['expense' => $agg[$i]['expense'] + $e, 'accum' => $agg[$i]['accum'] + $ac, 'book' => $agg[$i]['book'] + $bk];

            return $agg;
        };

        $groups = [];
        $grandCost = 0.0; $grandCells = $blank(); $no = 0;
        foreach (BsFixedAsset::CATEGORIES as $catKey => $catLabel) {
            $catAssets = $assets->where('category', $catKey)->values();
            if ($catAssets->isEmpty()) {
                continue;
            }
            $rows = []; $subCost = 0.0; $subMonthly = 0.0; $subCells = $blank();
            foreach ($catAssets as $a) {
                $no++;
                $cost = (float) $a->acquisition_cost;
                $subCost += $cost; $grandCost += $cost; $subMonthly += $a->monthly_depreciation;
                $cells = []; $prevAccum = 0.0; $prevMonths = 0;
                foreach ($periods as $i => $p) {
                    $at      = $accumAt($a, $p['end']);
                    $expense = round($at['accum'] - $prevAccum, 2);
                    $book    = round($cost - $at['accum'], 2);
                    $cells[] = ['months' => max(0, $at['months'] - $prevMonths), 'expense' => $expense, 'accum' => $at['accum'], 'book' => $book];
                    $subCells   = $add($subCells, $i, $expense, $at['accum'], $book);
                    $grandCells = $add($grandCells, $i, $expense, $at['accum'], $book);
                    $prevAccum = $at['accum']; $prevMonths = $at['months'];
                }
                $rows[] = [
                    'no' => $no, 'name' => $a->name, 'quantity' => $a->quantity, 'asset_number' => $a->asset_number,
                    'acquisition_date' => $a->acquisition_date->toDateString(), 'acquisition_cost' => $cost,
                    'useful_life_months' => (int) $a->useful_life_months, 'pic' => $a->pic, 'location' => $a->location,
                    'monthly' => $a->monthly_depreciation, 'cells' => $cells,
                ];
            }
            $groups[] = ['category' => $catKey, 'label' => $catLabel, 'assets' => $rows, 'subtotal' => ['cost' => round($subCost, 2), 'monthly' => round($subMonthly, 2), 'cells' => $subCells]];
        }

        return Inertia::render('BusinessSupport/FixedAsset/Summary', [
            'year'    => $year,
            'periods' => array_map(fn ($p) => ['key' => $p['key'], 'label' => $p['label']], $periods),
            'groups'  => $groups,
            'grand'   => ['cost' => round($grandCost, 2), 'cells' => $grandCells],
        ]);
    }
}
