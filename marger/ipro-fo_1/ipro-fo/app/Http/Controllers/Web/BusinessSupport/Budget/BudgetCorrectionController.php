<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetCorrectionService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Koreksi Budget (BO: Koreksi Budget Control / BudgetMutasi1.aspx, dok BC.MA/...).
 * Wizard: pilih COA/budget → input nilai baru → approval. Nilai baru berlaku setelah disetujui.
 */
class BudgetCorrectionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.correction.view'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        // Gate approval: default hanya koreksi yang SUDAH di-approve.
        $status = (string) $request->input('status', 'approved');

        $rows = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'doc_no' => $c->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'ref' => $c->budget?->doc_no, 'account' => $c->account?->code.' - '.$c->account?->name,
                'cost_center' => $c->costCenter?->name ?? 'Semua',
                'period' => self::labelPeriode($c),
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount, 'status' => $c->status,
                'approval_url' => $c->approval_id ? route('approval-inbox.show', $c->approval_id) : null,
                'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($c),
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsBudgetCorrection::STATUSES,
            'filterStatus' => $status,
            'pending' => BsBudgetCorrection::where('year', $year)->where('status', 'baru')->count(),
            'summary' => ['count' => $rows->count()],
        ]);
    }

    /** Wizard koreksi — daftar master budget approved untuk dipilih. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.correction.create'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->where('status', 'approved')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Create', [
            'year' => $year, 'budgets' => $budgets, 'monthLabels' => BsBudget::MONTHS,
        ]);
    }

    /**
     * Simpan koreksi PER BULAN: isian = nilai baru tiap bulan; yang disimpan selisihnya terhadap
     * anggaran bulan itu saat ini. Masuk Approval Inbox (Finance — chain KB-DEFAULT), berlaku setelah disetujui.
     */
    public function store(Request $request, BudgetCorrectionService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('budget.correction.create'), 403);
        $v = $request->validate([
            'budget_id'   => ['required', 'integer', 'exists:bs_budgets,id'],
            'corr_date'   => ['required', 'date'],
            'months'      => ['required', 'array'],
            'months.*'    => ['nullable', 'numeric', 'min:0'],
            'note'        => ['nullable', 'string', 'max:200'],
        ], [], ['months.*' => 'Nilai baru']);

        $budget = BsBudget::findOrFail($v['budget_id']);
        abort_unless($budget->status === 'approved', 422, 'Hanya anggaran yang sudah disetujui yang bisa dikoreksi.');
        $tambahan = [];
        foreach ($v['months'] as $m => $baru) {
            $m = (int) $m;
            if ($m < 1 || $m > 12 || $baru === null || $baru === '') {
                continue;
            }
            $delta = round((float) $baru - \App\Domain\BusinessSupport\Services\BudgetGuardService::anggaranBulan($budget, $m), 2);
            if ($delta != 0.0) {
                $tambahan[$m] = $delta;
            }
        }
        if ($tambahan === []) {
            return back()->withInput()->with('error', 'Belum ada bulan yang nilainya diubah.');
        }

        $c = DB::transaction(fn () => $service->ajukanManual($budget, $tambahan, $v['corr_date'], $v['note'] ?? null, $request->user()->id));

        return to_route('bs.budget.correction.index', ['status' => 'baru'])
            ->with('success', "Koreksi {$c->doc_no} diajukan ke Approval Inbox (Finance).");
    }

    /**
     * Inbox approval batch koreksi — termasuk koreksi dari invoice hutang & layar Koreksi Budget
     * yang berjalan di Approval Inbox (approval_id). Untuk yang itu, Setujui/Tolak di sini =
     * bertindak di tahap yang sedang menunggu (approval yang sama, dua pintu); kolom "Tahap"
     * menunjukkan siapa gilirannya.
     */
    public function approvals(Request $request, ApprovalInboxQuery $inbox): Response
    {
        $user = $request->user();
        $koreksi = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name', 'approval.steps'])
            ->where('status', 'baru')->orderBy('doc_no')->get();
        // Dokumen approval = koreksi itu sendiri — dipasang langsung supaya stages() tidak memuat ulang (lazy load).
        $koreksi->each(fn ($c) => $c->approval?->setRelation('approvable', $c));
        // Turunan invoice (tanpa approval sendiri): tampil untuk dilihat, diputuskan di approval invoicenya.
        $ikut = app(\App\Domain\BusinessSupport\Services\BudgetIkutInvoice::class);
        $induk = $koreksi->mapWithKeys(fn ($c) => [$c->id => $ikut->induk($c)?->load('steps')]);
        $bisa = fn (BsBudgetCorrection $c) => $induk[$c->id] ? false : ($c->approval ? $inbox->canAct($user, $c->approval)
            : (bool) $user?->canAny(['budget.correction.approve', 'businesssupport.approval.act']));
        abort_unless($user?->can('budget.correction.approve') || $koreksi->contains(fn ($c) => $c->approval && $bisa($c)), 403);

        $invoice = \App\Domain\BusinessSupport\Models\BsApInvoice::query();
        $rows = $koreksi->map(function ($c) use ($bisa, $inbox, $invoice, $induk) {
            $ap = $induk[$c->id] ?? $c->approval;
            $tahap = $ap ? collect($inbox->stages($ap))->firstWhere('state', 'current') : null;

            return [
                'id' => $c->id, 'doc_no' => $c->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'account' => $c->account?->code.' - '.$c->account?->name, 'cost_center' => $c->costCenter?->name ?? 'Semua',
                'period' => self::labelPeriode($c),
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount,
                'tambahan' => round(array_sum($c->tambahanBulan()), 2),
                'invoice' => match ($c->source_type) {
                    BsBudgetCorrection::SOURCE_INVOICE => (clone $invoice)->whereKey($c->source_id)->value('invoice_number'),
                    \App\Domain\BusinessSupport\Models\BsApBudgetRequest::SOURCE => \App\Domain\BusinessSupport\Models\BsApBudgetRequest::query()->whereKey($c->source_id)->value('invoice_number'),
                    default => null,
                },
                'tahap' => $tahap ? trim(($induk[$c->id] ? 'Ikut approval invoice · ' : '').$tahap['label'].($tahap['role'] ? ' — '.$tahap['role'] : '')) : null,
                'approval_url' => $ap ? route('approval-inbox.show', $ap->id) : null,
                'ikut_invoice' => (bool) $induk[$c->id],
                'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($c),
                'bisa' => $bisa($c),
            ];
        })->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Approvals', [
            'rows' => $rows, 'canApprove' => $rows->contains('bisa', true),
            'summary' => ['count' => $rows->count()],
        ]);
    }

    /** Approve menerapkan Nilai Baru ke master budget; reject hanya menandai. */
    public function approveBatch(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budget_corrections,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';
        $bolehLama = (bool) $request->user()?->canAny(['budget.correction.approve', 'businesssupport.approval.act']);

        // Koreksi di Approval Inbox (approval_id): bertindak di tahap yang menunggu lewat ActOnApproval —
        // hak per tahap sama dengan Inbox; tahap terakhir disetujui → diterapkan (SyncBudgetCorrectionStatus).
        // Koreksi lama tanpa approval_id: diputuskan langsung seperti dulu.
        $hasil = ['final' => 0, 'tahap' => 0];
        $gagal = [];
        foreach (BsBudgetCorrection::with('approval.steps')->whereIn('id', $v['ids'])->where('status', 'baru')->orderBy('id')->get() as $c) {
            try {
                if (app(\App\Domain\BusinessSupport\Services\BudgetIkutInvoice::class)->induk($c)) {
                    $gagal[] = $c->doc_no.': ikut approval invoice — putuskan di Approval Inbox';

                    continue;
                }
                if ($c->approval) {
                    app(ActOnApproval::class)->execute($c->approval, $approve ? 'approve' : 'reject');
                    $c->refresh()->status !== 'baru' ? $hasil['final']++ : $hasil['tahap']++;
                } elseif ($bolehLama) {
                    DB::transaction(fn () => app(BudgetCorrectionService::class)->putuskan($c, $approve, $request->user()->id));
                    $hasil['final']++;
                } else {
                    $gagal[] = $c->doc_no.': tidak berhak';
                }
            } catch (BusinessRuleException $e) {
                $gagal[] = $c->doc_no.': '.$e->getMessage();
            }
        }
        $pesan = trim(($hasil['final'] ? $hasil['final'].' koreksi '.($approve ? 'disetujui & diterapkan ke master budget. ' : 'ditolak. ') : '')
            .($hasil['tahap'] ? $hasil['tahap'].' koreksi disetujui di tahap Anda — lanjut ke tahap berikutnya. ' : ''));

        return $gagal ? back()->with($pesan !== '' ? 'warning' : 'error', trim($pesan.' Gagal: '.implode(' · ', $gagal)))
            : back()->with('success', $pesan);
    }

    /** "Nov, Des 2026" untuk koreksi per bulan; koreksi lama "Jan s/d Des 2026". */
    public static function labelPeriode(BsBudgetCorrection $c): string
    {
        if (is_array($c->months) && $c->months !== []) {
            return collect(array_keys($c->tambahanBulan()))->map(fn ($m) => BsBudget::MONTHS[$m - 1])->implode(', ').' '.$c->year;
        }

        return BsBudget::MONTHS[($c->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($c->period_to ?: 12) - 1].' '.$c->year;
    }
}
