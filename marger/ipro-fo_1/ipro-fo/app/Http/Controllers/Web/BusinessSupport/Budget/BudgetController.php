<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Models\BsBudgetMutation;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetRealizationService $realisasi) {}

    /** Hub modul Budget Control (mirror menu eProperty BO /BC/). */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);

        $year    = (int) now()->year;
        $budgets = BsBudget::where('year', $year)->get();
        $totalBudget = (float) $budgets->sum(fn ($b) => (float) $b->amount);
        $real    = 0.0;
        foreach ($budgets as $b) {
            $real += $this->realisasi->total($year, $b->account_id, $b->cost_center_id);
        }

        return Inertia::render('BusinessSupport/Budget/Hub', [
            'summary' => [
                'year'        => $year,
                'budget'      => round($totalBudget, 2),
                'realisasi'   => round($real, 2),
                'sisa'        => round($totalBudget - $real, 2),
                'lines'       => $budgets->count(),
                'pending'     => BsBudget::where('status', 'submitted')->count()
                                + BsBudgetAllocation::where('status', 'baru')->count()
                                + BsBudgetCorrection::where('status', 'baru')->count(),
            ],
        ]);
    }

    /** Print-Out — hub cetak laporan Budget Control. */
    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);

        return Inertia::render('BusinessSupport/Budget/PrintHub');
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);

        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->orderBy('doc_no')->get()
            ->map(function ($b) use ($year) {
                $real   = $this->realisasi->total($year, $b->account_id, $b->cost_center_id);
                $amount = (float) $b->amount;

                return [
                    'id' => $b->id, 'doc_no' => $b->doc_no, 'project' => $b->project,
                    'account' => $b->account?->code.' - '.$b->account?->name,
                    'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => $amount,
                    'months' => array_values($b->monthsArray()),
                    'realisasi' => $real, 'sisa' => $amount - $real,
                    'percent' => $amount > 0 ? round($real / $amount * 100, 1) : 0,
                    'health' => $amount <= 0 ? 'na' : ($real > $amount ? 'over' : ($real >= $amount * 0.9 ? 'warning' : 'aman')),
                    'wf_status' => $b->status ?? 'draft',
                    'notes' => $b->notes, 'approval_note' => $b->approval_note,
                ];
            })->values();

        return Inertia::render('BusinessSupport/Budget/Index', [
            'year'        => $year,
            'budgets'     => $budgets,
            'summary'     => [
                'amount'    => $budgets->sum('amount'),
                'realisasi' => $budgets->sum('realisasi'),
                'sisa'      => $budgets->sum('amount') - $budgets->sum('realisasi'),
                'approved'  => $budgets->where('wf_status', 'approved')->sum('amount'),
            ],
            'statuses'    => BsBudget::STATUSES,
            'monthLabels' => BsBudget::MONTHS,
            'mutations'   => BsBudgetMutation::where('year', $year)->with(['fromBudget.account:id,code,name', 'toBudget.account:id,code,name'])->latest('mutation_date')->latest('id')->limit(50)->get()
                ->map(fn ($m) => [
                    'id' => $m->id, 'kind' => $m->kind, 'date' => $m->mutation_date?->format('Y-m-d'), 'amount' => (float) $m->amount, 'note' => $m->note,
                    'from' => $m->fromBudget?->account?->code, 'to' => $m->toBudget?->account?->code,
                ]),
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->where('type', 'expense')->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /** Approval Budget Control — inbox batch master budget yang diajukan. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);

        $rows = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'submitted')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no, 'date' => $b->submitted_at?->format('Y-m-d'),
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
                'period' => 'Jan s/d Des '.$b->year,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Approvals', [
            'rows'       => $rows,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'summary'    => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    /** Approve/Reject banyak master budget sekaligus. */
    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budgets,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';
        BsBudget::whereIn('id', $v['ids'])->where('status', 'submitted')->update([
            'status'      => $approve ? 'approved' : 'rejected',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', $approve ? 'Master budget disetujui.' : 'Master budget ditolak.');
    }

    /** Ajukan anggaran (draft → submitted). */
    public function submit(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        if ($budget->status !== 'draft' && $budget->status !== 'rejected') {
            return back()->with('error', 'Hanya anggaran draft/ditolak yang dapat diajukan.');
        }
        $budget->update(['status' => 'submitted', 'submitted_at' => now(), 'approved_at' => null, 'approved_by' => null, 'approval_note' => null]);

        return back()->with('success', 'Anggaran diajukan untuk persetujuan.');
    }

    /** Setujui / tolak anggaran (submitted → approved|rejected). */
    public function approve(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        if ($budget->status !== 'submitted') {
            return back()->with('error', 'Hanya anggaran yang diajukan yang dapat diputuskan.');
        }
        $approve = $v['decision'] === 'approve';
        $budget->update([
            'status'        => $approve ? 'approved' : 'rejected',
            'approved_at'   => now(),
            'approved_by'   => $request->user()->id,
            'approval_note' => $v['note'] ?? null,
        ]);

        return back()->with('success', $approve ? 'Anggaran disetujui.' : 'Anggaran ditolak.');
    }

    /** Mutasi cepat (legacy): transfer antar baris / koreksi nilai satu baris (langsung). */
    public function mutate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'kind'           => ['required', 'in:transfer,koreksi'],
            'mutation_date'  => ['required', 'date'],
            'from_budget_id' => ['nullable', 'integer', 'exists:bs_budgets,id'],
            'to_budget_id'   => ['nullable', 'integer', 'exists:bs_budgets,id'],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($v, $request): void {
                $amount = round((float) $v['amount'], 2);
                if ($v['kind'] === 'transfer') {
                    $from = BsBudget::findOrFail($v['from_budget_id'] ?? 0);
                    $to   = BsBudget::findOrFail($v['to_budget_id'] ?? 0);
                    if ($from->id === $to->id) {
                        throw new BusinessRuleException('Baris asal dan tujuan tidak boleh sama.', errorCode: 'BUD_SAME');
                    }
                    if ((float) $from->amount < $amount) {
                        throw new BusinessRuleException('Anggaran asal tidak cukup untuk ditransfer.', errorCode: 'BUD_INSUFFICIENT');
                    }
                    $from->update(['amount' => round((float) $from->amount - $amount, 2)]);
                    $to->update(['amount' => round((float) $to->amount + $amount, 2)]);
                    $year = $from->year;
                } else {
                    $target = BsBudget::findOrFail($v['to_budget_id'] ?? ($v['from_budget_id'] ?? 0));
                    $target->update(['amount' => round((float) $target->amount + $amount, 2)]);
                    $year = $target->year;
                }
                BsBudgetMutation::create([
                    'year' => $year, 'kind' => $v['kind'], 'from_budget_id' => $v['from_budget_id'] ?? null,
                    'to_budget_id' => $v['to_budget_id'] ?? null, 'amount' => $amount,
                    'mutation_date' => $v['mutation_date'], 'note' => $v['note'] ?? null, 'created_by' => $request->user()->id,
                ]);
            });
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Mutasi anggaran tercatat.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v      = $this->validateData($request);
        $months = $this->monthsFrom($v);
        $sum    = array_sum($months);
        $amount = $sum > 0 ? $sum : (float) ($v['amount'] ?? 0);

        $existing = BsBudget::where('tenant_id', tenant_id())->where('year', $v['year'])
            ->where('cost_center_id', ($v['cost_center_id'] ?? null) ?: null)->where('account_id', $v['account_id'])->first();

        $payload = array_merge($months, [
            'project' => $v['project'] ?? null, 'amount' => $amount, 'notes' => $v['notes'] ?? null,
        ]);

        if ($existing) {
            $existing->update($payload);
        } else {
            BsBudget::create(array_merge($payload, [
                'tenant_id'  => tenant_id(),
                'doc_no'     => BsBudget::nextDocNo((int) $v['year'], $v['project'] ?? null),
                'year'       => $v['year'],
                'cost_center_id' => ($v['cost_center_id'] ?? null) ?: null,
                'account_id' => $v['account_id'],
                'status'     => 'draft',
                'created_by' => $request->user()->id,
            ]));
        }

        return back()->with('success', 'Anggaran disimpan.');
    }

    public function update(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v      = $this->validateData($request, $budget->id);
        $months = $this->monthsFrom($v);
        $sum    = array_sum($months);
        $amount = $sum > 0 ? $sum : (float) ($v['amount'] ?? 0);

        $budget->update(array_merge($months, [
            'year' => $v['year'], 'project' => $v['project'] ?? null,
            'cost_center_id' => ($v['cost_center_id'] ?? null) ?: null,
            'account_id' => $v['account_id'], 'amount' => $amount, 'notes' => $v['notes'] ?? null,
        ]));

        return back()->with('success', 'Anggaran diperbarui.');
    }

    public function destroy(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $budget->delete();

        return back()->with('success', 'Anggaran dihapus.');
    }

    /** @param array<string,mixed> $v @return array<string,float> m1..m12 */
    private function monthsFrom(array $v): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out['m'.$m] = round((float) ($v['m'.$m] ?? 0), 2);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?int $id = null): array
    {
        $rules = [
            'year'           => ['required', 'integer', 'min:2000', 'max:2100'],
            'project'        => ['nullable', 'string', 'max:30'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'         => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:200'],
        ];
        for ($m = 1; $m <= 12; $m++) {
            $rules['m'.$m] = ['nullable', 'numeric', 'min:0'];
        }

        return $request->validate($rules);
    }

    /** Export Budget Control ke Excel (.xls) dengan anggaran & realisasi per bulan (Jan–Des). */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);
        $year    = (int) $request->integer('year', (int) now()->year);
        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])->where('year', $year)->orderBy('doc_no')->get();

        $months = BsBudget::MONTHS;
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $head = '<tr style="background:#0f766e;color:#fff"><th>No. Budget</th><th>Akun</th><th>Cost Center</th><th>Budget</th>';
        foreach ($months as $m) {
            $head .= '<th>'.$m.'</th>';
        }
        $head .= '<th>Total Realisasi</th><th>Sisa</th><th>%</th></tr>';

        $body = '';
        foreach ($budgets as $b) {
            $monthly = $this->realisasi->byMonth($year, $b->account_id, $b->cost_center_id);
            $total   = array_sum($monthly);
            $amount  = (float) $b->amount;
            $body .= '<tr><td>'.$e($b->doc_no).'</td><td>'.$e($b->account?->code.' - '.$b->account?->name).'</td><td>'.$e($b->costCenter?->name ?? 'Semua').'</td><td>'.$rp($amount).'</td>';
            for ($mo = 1; $mo <= 12; $mo++) {
                $body .= '<td>'.$rp($monthly[$mo] ?? 0).'</td>';
            }
            $body .= '<td>'.$rp($total).'</td><td>'.$rp($amount - $total).'</td><td>'.($amount > 0 ? round($total / $amount * 100, 1) : 0).'%</td></tr>';
        }

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1">'.$head.$body.'</table></body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="Budget-Control-'.$year.'.xls"');
    }
}
