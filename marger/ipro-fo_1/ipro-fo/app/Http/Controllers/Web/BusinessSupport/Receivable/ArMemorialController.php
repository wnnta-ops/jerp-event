<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArMemorial;
use App\Domain\BusinessSupport\Services\ArMemorialService;
use App\Domain\Master\Customer\Models\Customer;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArMemorialController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.memorial.view'), 403);

        $rows = BsArMemorial::with(['customer:id,code,full_name', 'counterAccount:id,code,name'])
            ->where('is_writeoff', false)
            ->when($request->input('type'), fn ($q, $v) => $q->where('trx_type', $v))
            ->latest('memorial_date')->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Receivable/Memorial/Index', [
            'memorials' => $rows,
            'types'     => BsArMemorial::TYPES,
            'filters'   => $request->only('type'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.memorial.view'), 403);

        return Inertia::render('BusinessSupport/Receivable/Memorial/Form', [
            'customers'    => Customer::orderBy('full_name')->limit(1000)->get(['id', 'code', 'full_name']),
            'accounts'     => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'openInvoices' => BsArInvoice::where('status', 'open')->get(['id', 'invoice_number', 'customer_id', 'description', 'total', 'net_receivable', 'paid_amount'])
                ->map(fn ($i) => ['id' => $i->id, 'invoice_number' => $i->invoice_number, 'customer_id' => $i->customer_id, 'description' => $i->description, 'outstanding' => (float) $i->outstanding]),
            'types'        => BsArMemorial::TYPES,
            'kasir'        => $request->user()->name,
        ]);
    }

    public function store(Request $request, ArMemorialService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('ar.memorial.create'), 403);

        $v = $request->validate([
            'memorial_date'      => ['required', 'date'],
            'customer_id'        => ['required', 'integer', 'exists:customers,id'],
            'ar_invoice_id'      => ['nullable', 'integer', 'exists:bs_ar_invoices,id'],
            'trx_type'           => ['required', 'in:penambahan,penghapusan'],
            'counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'description'        => ['required', 'string', 'max:255'],
            'kasir'              => ['nullable', 'string', 'max:150'],
            'manual_no'          => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $memo = $svc->create($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.memorial.index')->with('success', "Memorial {$memo->memorial_number} terbit — jurnal otomatis terbentuk.");
    }
}
