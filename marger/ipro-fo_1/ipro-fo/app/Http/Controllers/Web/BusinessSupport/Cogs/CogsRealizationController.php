<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogsCategory;
use App\Domain\BusinessSupport\Models\BsCogsLocation;
use App\Domain\BusinessSupport\Models\BsCogsRealization;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** COGS Saldo Awal Realisasi + realisasi budget. */
class CogsRealizationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $rows = BsCogsRealization::with(['category:id,code,name', 'location:id,name'])
            ->where('year', $year)->orderByDesc('id')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'doc_no' => $r->doc_no, 'date' => $r->realization_date?->format('Y-m-d'), 'type' => $r->type,
                'category' => $r->category?->code.' '.$r->category?->name, 'location' => $r->location?->name ?? '—',
                'amount' => (float) $r->amount, 'note' => $r->note,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Realization/Index', [
            'year' => $year, 'rows' => $rows, 'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Realization/Create', [
            'projects'   => Project::orderBy('name')->get(['id', 'name']),
            'locations'  => BsCogsLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => BsCogsCategory::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'default_account_id']),
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $v = $request->validate([
            'project_id'       => ['required', 'integer'],
            'location_id'      => ['nullable', 'integer'],
            'category_id'      => ['nullable', 'integer'],
            'account_id'       => ['nullable', 'integer'],
            'realization_date' => ['required', 'date'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'type'             => ['required', 'in:saldo_awal,realisasi'],
            'note'             => ['nullable', 'string', 'max:200'],
        ]);
        $year = (int) date('Y', strtotime($v['realization_date']));
        BsCogsRealization::create(array_merge($v, [
            'tenant_id' => tenant_id(), 'year' => $year,
            'doc_no'    => app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
                ->next('CG.SAR', \Illuminate\Support\Carbon::parse($v['realization_date']), [], fn () => $this->nextDocNoLama($v, $year)),
            'created_by' => $request->user()->id,
        ]));

        return to_route('bs.cogs.realization.index')->with('success', 'Saldo awal / realisasi tercatat.');
    }

    /**
     * Cara lama — dipakai bila master numerator tidak bisa dipakai.
     *
     * @param  array<string,mixed>  $v
     */
    private function nextDocNoLama(array $v, int $year): string
    {
        return 'CG.SAR/'.date('y', strtotime($v['realization_date'])).'/'.str_pad((string) (BsCogsRealization::whereYear('realization_date', $year)->count() + 1), 5, '0', STR_PAD_LEFT);
    }
}
