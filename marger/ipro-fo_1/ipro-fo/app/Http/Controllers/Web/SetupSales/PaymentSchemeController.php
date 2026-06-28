<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Domain\Sales\Pricing\Models\PaymentSchemeTerm;
use App\Domain\Sales\Pricing\Services\PaymentSchemeBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Skema Cara Bayar (eProperty: Setup Sales → Skema Cara Bayar).
 * Builder Rumus Global / Nilai / Jadwal + generate termin.
 */
class PaymentSchemeController extends Controller
{
    public function __construct(private readonly PaymentSchemeBuilder $builder) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $rows = PaymentScheme::query()
            ->with('project:id,name')
            ->withCount('terms')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderBy('code')->paginate(25)->withQueryString();

        return Inertia::render('SetupSales/Scheme/Index', [
            'rows'    => $rows,
            'filters' => $request->only(['search', 'type']),
            'types'   => PaymentScheme::TYPES,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);

        return Inertia::render('SetupSales/Scheme/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $v = $this->validateData($request);
        $this->assertUniqueCode($v['code'], $v['project_id'] ?? null);

        $scheme = DB::transaction(function () use ($v) {
            $scheme = PaymentScheme::create($v + ['installment_months' => (int) $v['inst_count']]);
            $this->syncTerms($scheme);

            return $scheme;
        });

        return redirect()->route('setup-sales.scheme.show', $scheme)->with('success', "Skema {$scheme->code} dibuat.");
    }

    public function show(Request $request, PaymentScheme $scheme): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);
        $scheme->load('terms', 'project:id,name');

        // Simulasi memakai contoh PV (1 Milyar) supaya user lihat jadwal nyata.
        $samplePv = 1_000_000_000.0;
        $sim = $this->builder->simulate($scheme->toArray(), $samplePv);

        return Inertia::render('SetupSales/Scheme/Show', [
            'scheme'      => array_merge($scheme->toArray(), [
                'type_label'   => PaymentScheme::TYPES[$scheme->type] ?? $scheme->type,
                'bf_label'     => PaymentScheme::BF_TREATMENTS[$scheme->bf_treatment] ?? $scheme->bf_treatment,
            ]),
            'simulation'  => $sim,
            'samplePv'    => $samplePv,
        ]);
    }

    public function edit(Request $request, PaymentScheme $scheme): Response
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);

        return Inertia::render('SetupSales/Scheme/Form', $this->formProps() + ['scheme' => $scheme->toArray()]);
    }

    public function update(Request $request, PaymentScheme $scheme): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $v = $this->validateData($request);
        $this->assertUniqueCode($v['code'], $v['project_id'] ?? null, $scheme->id);

        DB::transaction(function () use ($scheme, $v) {
            $scheme->update($v + ['installment_months' => (int) $v['inst_count']]);
            $this->syncTerms($scheme);
        });

        return redirect()->route('setup-sales.scheme.show', $scheme)->with('success', 'Skema diperbarui.');
    }

    public function destroy(Request $request, PaymentScheme $scheme): RedirectResponse
    {
        abort_unless($request->user()?->can('setupsales.manage'), 403);
        $scheme->delete();

        return redirect()->route('setup-sales.scheme.index')->with('success', 'Skema dihapus.');
    }

    /** Preview live — hitung jadwal dari rumus + contoh PV (tanpa simpan). */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);
        $v = $request->validate([
            'sample_pv'   => ['nullable', 'numeric', 'min:0'],
            'start_date'  => ['nullable', 'date'],
        ] + $this->rumusRules());

        $pv = (float) ($v['sample_pv'] ?? 1_000_000_000);
        $sim = $this->builder->simulate($v, $pv, $v['start_date'] ?? null);

        return response()->json($sim);
    }

    /** Regenerasi termin dari rumus tersimpan. */
    private function syncTerms(PaymentScheme $scheme): void
    {
        $scheme->terms()->delete();
        foreach ($this->builder->template($scheme->toArray()) as $t) {
            $scheme->terms()->create($t);
        }
    }

    private function assertUniqueCode(string $code, ?int $projectId, ?int $ignoreId = null): void
    {
        $exists = PaymentScheme::query()
            ->where('code', $code)
            ->where('project_id', $projectId)
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();
        abort_if($exists, 422, "Kode skema \"{$code}\" sudah dipakai pada project ini.");
    }

    /** @return array<string,mixed> */
    private function formProps(): array
    {
        return [
            'projects'     => Project::orderBy('name')->get(['id', 'name']),
            'types'        => PaymentScheme::TYPES,
            'bfTreatments' => PaymentScheme::BF_TREATMENTS,
        ];
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'project_id'    => ['nullable', 'integer', 'exists:projects,id'],
            'code'          => ['required', 'string', 'max:32'],
            'name'          => ['required', 'string', 'max:150'],
            'type'          => ['required', 'in:cash_keras,cicilan,kpr,kpa,sewa_beli,mixed'],
            'discount_pct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'down_payment_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active'     => ['boolean'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ] + $this->rumusRules());
    }

    /** Aturan validasi Rumus Global/Nilai/Jadwal. */
    private function rumusRules(): array
    {
        return [
            'present_value'     => ['nullable'],  // boolean (header) ATAU numeric (preview); ditangani terpisah
            'tiered_discounts'  => ['nullable', 'array'],
            'tiered_discounts.*'=> ['numeric', 'min:0', 'max:100'],
            'booking_fee'       => ['nullable', 'numeric', 'min:0'],
            'bf_treatment'      => ['nullable', 'in:dp1,angsuran1,dp_disebar,angsuran_disebar,tidak'],
            'dp_count'          => ['nullable', 'integer', 'min:0', 'max:60'],
            'dp_basis'          => ['nullable', 'in:percent,amount'],
            'dp_value'          => ['nullable', 'numeric', 'min:0'],
            'inst_count'        => ['nullable', 'integer', 'min:0', 'max:600'],
            'inst_basis'        => ['nullable', 'in:percent,amount'],
            'inst_value'        => ['nullable', 'numeric', 'min:0'],
            'schedule_first'    => ['nullable', 'integer', 'min:0', 'max:120'],
            'schedule_interval' => ['nullable', 'integer', 'min:1', 'max:60'],
            'schedule_unit'     => ['nullable', 'in:month,day'],
        ];
    }
}
