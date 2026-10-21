<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArKuitansi;
use App\Domain\BusinessSupport\Services\ArKuitansiService;
use App\Domain\Master\Customer\Models\Customer;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArKuitansiController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.kuitansi.view'), 403);

        $rows = BsArKuitansi::with('customer:id,code,full_name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('kuitansi_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('kuitansi_date')->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Receivable/Kuitansi/Index', [
            'kuitansis' => $rows,
            'filters'   => $request->only(['status', 'search']),
            'summary'   => ['active' => (float) BsArKuitansi::where('status', 'active')->sum('total'), 'count' => BsArKuitansi::where('status', 'active')->count()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.kuitansi.create'), 403);

        $open = BsArInvoice::where('status', 'open')->with('customer:id,code,full_name')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'customer_id' => $i->customer_id,
                'description' => $i->description, 'outstanding' => (float) $i->outstanding,
                'due_date' => $i->due_date?->format('Y-m-d'),
            ])->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        return Inertia::render('BusinessSupport/Receivable/Kuitansi/Form', [
            'customers'    => Customer::orderBy('full_name')->limit(1000)->get(['id', 'code', 'full_name']),
            'openInvoices' => $open,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
            'alat'         => BsArKuitansi::ALAT,
            'kasir'        => $request->user()->name,
        ]);
    }

    public function store(Request $request, ArKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('ar.kuitansi.create'), 403);

        $v = $request->validate([
            'kuitansi_date'       => ['required', 'date'],
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'cash_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'alat_transaksi'      => ['required', 'in:tunai,transfer,giro'],
            'kasir'               => ['nullable', 'string', 'max:150'],
            'manual_no'           => ['nullable', 'string', 'max:80'],
            'description'         => ['nullable', 'string', 'max:255'],
            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.ar_invoice_id'  => ['required', 'integer', 'exists:bs_ar_invoices,id'],
            'lines.*.amount'         => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $kui = $svc->create($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.kuitansi.show', $kui)->with('success', "Kuitansi {$kui->kuitansi_number} terbit — jurnal otomatis terbentuk.");
    }

    public function show(Request $request, BsArKuitansi $kuitansi): Response
    {
        abort_unless($request->user()?->can('ar.kuitansi.view'), 403);

        return Inertia::render('BusinessSupport/Receivable/Kuitansi/Show', [
            'kuitansi' => $kuitansi->load(['customer:id,code,full_name', 'cashAccount:id,code,name', 'lines.invoice:id,invoice_number,description']),
            'alat'     => BsArKuitansi::ALAT,
        ]);
    }

    public function void(Request $request, BsArKuitansi $kuitansi, ArKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ar.kuitansi.void', 'businesssupport.approval.act']), 403);

        try {
            $svc->void($kuitansi, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.kuitansi.show', $kuitansi)->with('success', 'Kuitansi di-void — penerimaan dibatalkan, jurnal balik terbentuk.');
    }
}
