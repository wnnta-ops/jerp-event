<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Purchasing;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\BusinessSupport\Services\StockService;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $rows = BsPurchaseOrder::query()
            ->with('supplier:id,code,name')
            ->withCount('lines')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where('po_number', 'like', "%{$s}%"))
            ->latest('po_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Index', [
            'orders'  => $rows,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Form', [
            'suppliers' => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'items'     => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'projects'  => Project::orderBy('name')->get(['id', 'name']),
            'ppn_default' => (float) config('domain.tax.ppn_default_pct', 11),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'supplier_id'  => ['required', 'integer', 'exists:bs_suppliers,id'],
            'project_id'   => ['nullable', 'integer', 'exists:projects,id'],
            'po_date'      => ['required', 'date'],
            'due_date'     => ['nullable', 'date'],
            'po_type'      => ['nullable', 'in:barang,jasa'],
            'term_payment' => ['nullable', 'string', 'max:100'],
            'ppn_pct'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_pct'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $po = DB::transaction(function () use ($v, $request) {
            $subtotal = 0;
            foreach ($v['lines'] as $l) {
                $subtotal += (float) $l['qty'] * (float) $l['unit_price'];
            }
            $ppnPct = (float) ($v['ppn_pct'] ?? 0);
            $ppnAmount = round($subtotal * $ppnPct / 100, 2);

            $po = BsPurchaseOrder::create([
                'supplier_id'  => $v['supplier_id'],
                'project_id'   => $v['project_id'] ?? null,
                'po_number'    => app(StockService::class)->nextNumber('PO', BsPurchaseOrder::class, 'po_date'),
                'po_date'      => $v['po_date'],
                'due_date'     => $v['due_date'] ?? null,
                'po_type'      => $v['po_type'] ?? 'barang',
                'term_payment' => $v['term_payment'] ?? null,
                'subtotal'     => $subtotal,
                'ppn_pct'      => $ppnPct,
                'ppn_amount'   => $ppnAmount,
                'pph_pct'      => (float) ($v['pph_pct'] ?? 0),
                'total'        => $subtotal + $ppnAmount,
                'status'       => 'draft',
                'notes'        => $v['notes'] ?? null,
                'created_by'   => $request->user()->id,
            ]);
            foreach ($v['lines'] as $l) {
                $amount = (float) $l['qty'] * (float) $l['unit_price'];
                $po->lines()->create(['item_id' => $l['item_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'amount' => $amount]);
            }
            return $po;
        });

        return redirect()->route('bs.po.show', $po)->with('success', "PO {$po->po_number} dibuat.");
    }

    public function show(Request $request, BsPurchaseOrder $po): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Show', [
            'order' => $po->load(['lines.item:id,code,name', 'supplier']),
            'items' => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'cashAccounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'advanceAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '10107%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'     => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
            'defaultAdvance'  => BsAccount::where('code', config('business_support.purchase_order.advance'))->value('id'),
        ]);
    }

    /** Revisi PO — ganti baris & hitung ulang total (selagi belum ditutup). */
    public function revise(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        if ($po->status === 'closed') {
            return back()->with('error', 'PO sudah ditutup, tidak dapat direvisi.');
        }

        $v = $request->validate([
            'po_date'      => ['required', 'date'],
            'due_date'     => ['nullable', 'date'],
            'term_payment' => ['nullable', 'string', 'max:100'],
            'ppn_pct'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_pct'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'        => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($po, $v): void {
            $subtotal = 0.0;
            foreach ($v['lines'] as $l) {
                $subtotal += (float) $l['qty'] * (float) $l['unit_price'];
            }
            $ppnPct    = (float) ($v['ppn_pct'] ?? 0);
            $ppnAmount = round($subtotal * $ppnPct / 100, 2);

            $po->update([
                'po_date'      => $v['po_date'],
                'due_date'     => $v['due_date'] ?? null,
                'term_payment' => $v['term_payment'] ?? $po->term_payment,
                'subtotal'     => $subtotal,
                'ppn_pct'      => $ppnPct,
                'ppn_amount'   => $ppnAmount,
                'pph_pct'      => (float) ($v['pph_pct'] ?? 0),
                'total'        => round($subtotal + $ppnAmount, 2),
                'notes'        => $v['notes'] ?? $po->notes,
                'revision_count' => (int) $po->revision_count + 1,
                'revised_at'   => now(),
            ]);
            $po->lines()->delete();
            foreach ($v['lines'] as $l) {
                $po->lines()->create([
                    'item_id' => $l['item_id'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'],
                    'amount' => (float) $l['qty'] * (float) $l['unit_price'],
                ]);
            }
        });

        return redirect()->route('bs.po.show', $po)->with('success', "PO {$po->po_number} direvisi (revisi ke-".($po->revision_count).").");
    }

    /** Posting Termin PO — uang muka pembelian: Dr Uang Muka / Cr Kas-Bank. */
    public function postTermin(Request $request, BsPurchaseOrder $po, JournalService $journal): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        if ($po->status === 'closed') {
            return back()->with('error', 'PO sudah ditutup.');
        }

        $v = $request->validate([
            'termin_date'     => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'advance_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'note'            => ['nullable', 'string', 'max:255'],
        ]);

        $amount = round((float) $v['amount'], 2);
        $alreadyDp = round((float) $po->dp_amount, 2);
        if ($amount + $alreadyDp > (float) $po->total + 0.01) {
            return back()->with('error', 'Total uang muka melebihi nilai PO.');
        }

        try {
            $jrnl = $journal->post([
                'journal_date' => $v['termin_date'],
                'source'       => 'cash',
                'reference'    => $po->po_number,
                'description'  => 'Uang muka PO '.$po->po_number.($v['note'] ?? '' ? ' — '.$v['note'] : ''),
                'created_by'   => $request->user()->id,
            ], [
                ['account_id' => (int) $v['advance_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Uang muka pembelian'],
                ['account_id' => (int) $v['cash_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Kas/Bank keluar'],
            ]);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $po->update([
            'dp_amount'     => round($alreadyDp + $amount, 2),
            'dp_account_id' => (int) $v['advance_account_id'],
            'dp_journal_id' => $jrnl->id,
        ]);

        return redirect()->route('bs.po.show', $po)->with('success', 'Termin/uang muka PO tercatat — jurnal otomatis (Dr Uang Muka / Cr Kas).');
    }

    /** Adjust PPN PO — set ulang persentase PPN & hitung ulang total. */
    public function adjustPpn(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        if ($po->status === 'closed') {
            return back()->with('error', 'PO sudah ditutup.');
        }

        $v = $request->validate([
            'ppn_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $ppnAmount = round((float) $po->subtotal * (float) $v['ppn_pct'] / 100, 2);
        $po->update([
            'ppn_pct'    => (float) $v['ppn_pct'],
            'ppn_amount' => $ppnAmount,
            'total'      => round((float) $po->subtotal + $ppnAmount, 2),
        ]);

        return redirect()->route('bs.po.show', $po)->with('success', 'PPN PO disesuaikan & total dihitung ulang.');
    }

    public function approve(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        if ($po->status !== 'draft') {
            return back()->with('error', 'PO sudah diproses.');
        }
        $po->update(['status' => 'approved', 'approved_at' => now()]);

        return back()->with('success', "PO {$po->po_number} disetujui.");
    }

    public function close(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $po->update(['status' => 'closed']);

        return back()->with('success', "PO {$po->po_number} ditutup.");
    }
}
