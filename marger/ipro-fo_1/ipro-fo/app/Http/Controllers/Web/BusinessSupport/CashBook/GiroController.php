<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGiro;
use App\Domain\BusinessSupport\Services\GiroService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GiroController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsGiro::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('due_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/CashBook/Giro/Index', [
            'giros'    => $rows,
            'statuses' => BsGiro::STATUSES,
            'filters'  => $request->only(['status']),
            'summary'  => [
                'in_hand' => (float) BsGiro::where('status', 'in_hand')->sum('amount'),
                'count'   => BsGiro::where('status', 'in_hand')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Giro/Form', [
            // Piutang asal (lawan) → SEMUA akun COA transaksi yang aktif (bisa diketik/cari di dropdown).
            'counterAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCounter'  => BsAccount::where('code', config('business_support.gl_accounts.receivable'))->value('id'),
        ]);
    }

    public function store(Request $request, GiroService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'receive_date'       => ['required', 'date'],
            'due_date'           => ['required', 'date', 'after_or_equal:receive_date'],
            'bank_name'          => ['required', 'string', 'max:100'],
            'cheque_number'      => ['required', 'string', 'max:60'],
            'drawer'             => ['required', 'string', 'max:150'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $giro = $svc->receive($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.giro.show', $giro)->with('success', "Cek/Giro {$giro->giro_number} diterima — jurnal otomatis terbentuk.");
    }

    public function show(Request $request, BsGiro $giro): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Giro/Show', [
            'giro'         => $giro->load(['counterAccount:id,code,name', 'cashAccount:id,code,name']),
            'statuses'     => BsGiro::STATUSES,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
        ]);
    }

    public function clear(Request $request, BsGiro $giro, GiroService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'settled_date'    => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->clear($giro, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.giro.show', $giro)->with('success', 'Cek/Giro cair — jurnal otomatis terbentuk.');
    }

    public function bounce(Request $request, BsGiro $giro, GiroService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate(['settled_date' => ['required', 'date']]);

        try {
            $svc->bounce($giro, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.giro.show', $giro)->with('success', 'Cek/Giro ditolak — piutang dipulihkan, jurnal otomatis terbentuk.');
    }
}
