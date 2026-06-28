<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Progress Retensi + Registrasi Retensi (pencairan retensi → Dr Hutang Retensi / Cr Kas, tutup SPK).
 */
class RetentionController extends Controller
{
    /** Progress Retensi — daftar SPK dengan retensi tertahan. */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrder::with(['supplier:id,name', 'project:id,code,name'])
            ->where('retention_held', '>', 0)->orderByDesc('retention_held')->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'project' => $w->project?->name,
                'contract_value' => (float) $w->contract_value, 'retention_held' => (float) $w->retention_held,
                'status' => $w->status,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Retention/Index', [
            'rows'    => $rows,
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('retention_held')],
        ]);
    }

    /** Registrasi Retensi — pilih SPK lalu cairkan retensi. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::with('supplier:id,name')->where('retention_held', '>', 0)
            ->orderByDesc('retention_held')->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'retention_held' => (float) $w->retention_held,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Retention/Create', [
            'spks'         => $spks,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
        ]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id'   => ['required', 'integer', 'exists:bs_work_orders,id'],
            'settled_date'    => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->releaseRetention($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.retention.index')->with('success', 'Retensi dicairkan & SPK ditutup — jurnal otomatis (Dr Hutang Retensi / Cr Kas).');
    }
}
