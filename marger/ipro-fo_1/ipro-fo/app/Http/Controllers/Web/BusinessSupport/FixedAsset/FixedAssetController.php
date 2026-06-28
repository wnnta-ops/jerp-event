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
            'category'           => ['required', 'in:bangunan,kendaraan,inventaris,tanah'],
            'acquisition_date'   => ['required', 'date'],
            'acquisition_cost'   => ['required', 'numeric', 'min:0.01'],
            'residual_value'     => ['nullable', 'numeric', 'min:0', 'lt:acquisition_cost'],
            'useful_life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'funding_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
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
}
