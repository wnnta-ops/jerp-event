<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Revenue;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BookedRevenueService;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookedRevenueController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $rows = BsBookedRevenue::query()
            ->with(['customer:id,code,full_name', 'unit:id,unit_code'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('br_number', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->latest('booking_date')->latest('id')
            ->paginate(50)->withQueryString();

        $rows->getCollection()->transform(fn ($r) => [
            'id'            => $r->id,
            'br_number'     => $r->br_number,
            'booking_date'  => $r->booking_date?->toDateString(),
            'customer'      => $r->customer ? ['code' => $r->customer->code, 'name' => $r->customer->full_name] : null,
            'unit'          => $r->unit?->unit_code,
            'amount'        => (float) $r->amount,
            'status'        => $r->status,
            'recognized_at' => $r->recognized_at?->toDateString(),
        ]);

        $sum = fn (string $status) => (float) BsBookedRevenue::where('status', $status)->sum('amount');

        return Inertia::render('BusinessSupport/BookedRevenue/Index', [
            'rows'    => $rows,
            'filters' => $request->only(['search', 'status']),
            'summary' => [
                'booked_total'     => $sum('booked'),
                'recognized_total' => $sum('recognized'),
                'booked_count'     => BsBookedRevenue::where('status', 'booked')->count(),
                'recognized_count' => BsBookedRevenue::where('status', 'recognized')->count(),
                'cancelled_count'  => BsBookedRevenue::where('status', 'cancelled')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        // Unit yang SUDAH punya Booked Revenue aktif (booked/recognized) dikecualikan —
        // yang dibatalkan (cancelled) boleh di-booking ulang.
        $bookedUnitIds = BsBookedRevenue::whereIn('status', ['booked', 'recognized'])
            ->whereNotNull('unit_id')->distinct()->pluck('unit_id')->all();

        $unitRows = Unit::with('cluster:id,name')
            ->whereNotIn('id', $bookedUnitIds)
            ->orderBy('unit_code')
            ->get(['id', 'unit_code', 'cluster_id', 'project_id', 'base_price', 'current_price', 'status']);

        // Customer per unit (dari booking terakhir unit tsb) → auto-isi saat unit dipilih.
        $unitCustomer = \App\Domain\Sales\Booking\Models\Booking::query()
            ->whereIn('unit_id', $unitRows->pluck('id'))
            ->whereNull('deleted_at')
            ->with('customer:id,code,full_name')
            ->latest('id')
            ->get(['id', 'unit_id', 'customer_id'])
            ->groupBy('unit_id')
            ->map(fn ($g) => $g->first());

        $units = $unitRows->map(fn ($u) => [
            'id'            => $u->id,
            'code'          => $u->unit_code,
            'cluster'       => $u->cluster?->name,
            'price'         => (float) ($u->current_price ?: $u->base_price),
            'status'        => $u->status instanceof \BackedEnum ? $u->status->value : $u->status,
            // Collection->get(): null-safe bila unit tsb belum punya booking (hindari "Undefined array key").
            'customer_id'   => $unitCustomer->get($u->id)?->customer_id,
            'customer_name' => $unitCustomer->get($u->id)?->customer?->full_name,
        ]);

        $accCode = fn (string $key) => (int) (BsAccount::where('code', config("business_support.gl_accounts.{$key}"))->value('id') ?? 0);

        return Inertia::render('BusinessSupport/BookedRevenue/Form', [
            'customers'   => Customer::orderBy('full_name')->get(['id', 'code', 'full_name'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->full_name]),
            'units'       => $units,
            'bookedCount' => count($bookedUnitIds),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // COA dari General Ledger (akun postable) untuk jurnal manual.
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'normal_balance']),
            // Akun default untuk prefill baris jurnal (boleh diganti user).
            'prefill'     => [
                'receivable_id' => $accCode('receivable'),
                'deferred_id'   => $accCode('unearned_revenue'),
                'ppn_out_id'    => $accCode('ppn_out'),
            ],
        ]);
    }

    public function store(Request $request, BookedRevenueService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'booking_date'    => ['required', 'date'],
            'customer_id'     => ['required', 'integer', 'exists:customers,id'],
            'cost_center_id'  => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'reference'       => ['nullable', 'string', 'max:150'],
            'description'     => ['nullable', 'string', 'max:200'],
            'units'            => ['required', 'array', 'min:1'],
            'units.*.unit_id'  => ['required', 'integer', 'distinct', 'exists:units,id'],
            'units.*.discount' => ['nullable', 'numeric', 'min:0'],
            // Jurnal manual: maks 3 baris debit + 3 baris kredit.
            'debits'              => ['array', 'max:3'],
            'debits.*.account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'debits.*.amount'     => ['nullable', 'numeric', 'min:0'],
            'credits'              => ['array', 'max:3'],
            'credits.*.account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'credits.*.amount'     => ['nullable', 'numeric', 'min:0'],
        ], [
            'units.required' => 'Pilih minimal 1 unit untuk di-booking.',
            'units.min'      => 'Pilih minimal 1 unit untuk di-booking.',
        ]);

        // Susun baris jurnal manual: lewati baris kosong; tolak baris setengah terisi.
        $lines = [];
        foreach (['debits' => 'debit', 'credits' => 'credit'] as $key => $side) {
            foreach ($v[$key] ?? [] as $row) {
                $acc = $row['account_id'] ?? null;
                $amt = round((float) ($row['amount'] ?? 0), 2);
                if (! $acc && $amt <= 0) {
                    continue; // baris kosong
                }
                if (! $acc || $amt <= 0) {
                    return back()->withInput()->with('error', 'Tiap baris jurnal harus berisi akun DAN nominal > 0 — atau kosongkan keduanya.');
                }
                $lines[] = [
                    'account_id' => (int) $acc,
                    'debit'      => $side === 'debit' ? $amt : 0,
                    'credit'     => $side === 'credit' ? $amt : 0,
                ];
            }
        }

        try {
            // JournalService memvalidasi: minimal 2 baris & total debit = total kredit.
            $brs = $svc->bookMany($v, $v['units'], $lines, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if (count($brs) === 1) {
            return redirect()->route('bs.booked-revenue.show', $brs[0]->id)
                ->with('success', "Booked Revenue {$brs[0]->br_number} dibuat — jurnal manual diposting.");
        }

        $total = number_format((float) collect($brs)->sum('amount'), 0, ',', '.');

        return redirect()->route('bs.booked-revenue.index')
            ->with('success', count($brs)." unit di-booking (total revenue Rp {$total}) — 1 jurnal manual diposting untuk seluruh transaksi.");
    }

    public function show(Request $request, BsBookedRevenue $bookedRevenue): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $bookedRevenue->load([
            'customer:id,code,full_name,phone,email',
            'unit:id,unit_code,base_price,current_price',
            'deferredAccount:id,code,name',
            'bookingJournal:id,journal_number,journal_date,total_debit',
            'recognitionJournal:id,journal_number,journal_date,total_debit',
        ]);

        return Inertia::render('BusinessSupport/BookedRevenue/Show', [
            'br' => [
                'id'            => $bookedRevenue->id,
                'br_number'     => $bookedRevenue->br_number,
                'booking_date'  => $bookedRevenue->booking_date?->toDateString(),
                'gross_price'   => (float) $bookedRevenue->gross_price,
                'discount'      => (float) $bookedRevenue->discount,
                'amount'        => (float) $bookedRevenue->amount,
                'status'        => $bookedRevenue->status,
                'reference'     => $bookedRevenue->reference,
                'description'   => $bookedRevenue->description,
                'recognized_at' => $bookedRevenue->recognized_at?->toDateString(),
                'cancelled_at'  => $bookedRevenue->cancelled_at?->toDateString(),
                'customer'      => $bookedRevenue->customer ? [
                    'code' => $bookedRevenue->customer->code, 'name' => $bookedRevenue->customer->full_name,
                    'phone' => $bookedRevenue->customer->phone, 'email' => $bookedRevenue->customer->email,
                ] : null,
                'unit'          => $bookedRevenue->unit?->unit_code,
                'deferred'      => $bookedRevenue->deferredAccount ? $bookedRevenue->deferredAccount->code.' — '.$bookedRevenue->deferredAccount->name : null,
                'booking_journal'     => $bookedRevenue->bookingJournal?->journal_number,
                'recognition_journal' => $bookedRevenue->recognitionJournal?->journal_number,
            ],
        ]);
    }

    public function recognize(Request $request, BsBookedRevenue $bookedRevenue, BookedRevenueService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        try {
            $svc->recognize($bookedRevenue, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pendapatan {$bookedRevenue->br_number} diakui — jurnal otomatis (Dr Pendapatan Diterima Dimuka / Cr Penjualan Usaha).");
    }

    public function cancel(Request $request, BsBookedRevenue $bookedRevenue, BookedRevenueService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        try {
            $svc->cancel($bookedRevenue, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Booked Revenue {$bookedRevenue->br_number} dibatalkan — jurnal balik diposting.");
    }
}
