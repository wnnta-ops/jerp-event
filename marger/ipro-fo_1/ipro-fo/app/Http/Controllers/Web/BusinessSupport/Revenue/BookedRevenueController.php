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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    // ── Report / Query: rekap keseluruhan Booked Revenue per unit ──
    // Menyatukan milestone per unit: BAST · AJB · Retensi (tgl+nominal) · UTJ & DP (tgl+nominal) ·
    // Faktur Pajak (tgl+nominal+nomor). Basis = unit yang punya Booked Revenue.

    public function report(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        [$rows, $summary] = $this->reportRows($request);

        return Inertia::render('BusinessSupport/BookedRevenue/Report', [
            'rows'     => $rows->values(),
            'filters'  => $request->only(['search', 'status']),
            'summary'  => $summary,
            'statuses' => ['booked', 'recognized', 'cancelled'],
        ]);
    }

    public function reportExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        [$rows] = $this->reportRows($request);
        $header = ['Unit', 'Cluster', 'Customer', 'No. BR', 'Tgl BR', 'Nilai BR', 'Status',
            'Tgl BAST', 'Tgl AJB', 'Notaris', 'Tgl Retensi', 'Nominal Retensi',
            'Tgl UTJ&DP', 'Nominal UTJ&DP', 'Tgl Faktur Pajak', 'Nominal Faktur Pajak', 'No. Faktur Pajak'];

        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['unit'], $r['cluster'], $r['customer'], $r['br_number'], $r['br_date'], $r['br_amount'], $r['br_status'],
                    $r['bast_date'], $r['ajb_date'], $r['ajb_notary'], $r['retensi_date'], $r['retensi_amount'],
                    $r['utjdp_date'], $r['utjdp_amount'], $r['fp_date'], $r['fp_amount'], $r['fp_number'],
                ]);
            }
            fclose($out);
        }, 'booked-revenue-report.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Susun baris report per-unit + ringkasan.
     *
     * @return array{0:\Illuminate\Support\Collection<int,array<string,mixed>>, 1:array<string,mixed>}
     */
    private function reportRows(Request $request): array
    {
        $brs = BsBookedRevenue::query()
            ->with(['customer:id,code,full_name', 'unit:id,unit_code,cluster_id', 'unit.cluster:id,name'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('br_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->latest('booking_date')->latest('id')
            ->get();

        $unitIds = $brs->pluck('unit_id')->filter()->unique()->values();

        // Booking terakhir per unit → jembatan ke dokumen milestone (BAST/AJB/FP/entri).
        $bookingByUnit = \App\Domain\Sales\Booking\Models\Booking::query()
            ->whereIn('unit_id', $unitIds)->whereNull('deleted_at')
            ->latest('id')->get(['id', 'unit_id'])
            ->groupBy('unit_id')->map(fn ($g) => $g->first());
        $bookingIds = $bookingByUnit->map->id->values();

        $bast = \App\Domain\Legal\Bast\Models\BastDocument::whereIn('booking_id', $bookingIds)
            ->get(['id', 'booking_id', 'handed_over_at'])->groupBy('booking_id')->map(fn ($g) => $g->sortByDesc('handed_over_at')->first());
        $ajb = \App\Domain\Legal\Ajb\Models\AjbDocument::whereIn('booking_id', $bookingIds)
            ->get(['id', 'booking_id', 'signed_at', 'notary_name'])->groupBy('booking_id')->map(fn ($g) => $g->sortByDesc('signed_at')->first());
        $fp = \App\Domain\Finance\FakturPajak\Models\FakturPajak::whereIn('booking_id', $bookingIds)
            ->get(['id', 'booking_id', 'issued_at', 'total', 'fp_number'])->groupBy('booking_id')->map(fn ($g) => $g->sortByDesc('issued_at')->first());
        $entries = \App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry::whereIn('booking_id', $bookingIds)
            ->get(['id', 'booking_id', 'source_type', 'doc_date', 'total']);
        $retByBooking = $entries->where('source_type', 'retensi')->groupBy('booking_id');
        $utjByBooking = $entries->where('source_type', 'utj_dp')->groupBy('booking_id');

        $rows = $brs->map(function ($br) use ($bookingByUnit, $bast, $ajb, $fp, $retByBooking, $utjByBooking) {
            $bid     = $bookingByUnit->get($br->unit_id)?->id;
            $bastDoc = $bid ? $bast->get($bid) : null;
            $ajbDoc  = $bid ? $ajb->get($bid) : null;
            $fpDoc   = $bid ? $fp->get($bid) : null;
            $ret     = $bid ? ($retByBooking->get($bid) ?? collect()) : collect();
            $utj     = $bid ? ($utjByBooking->get($bid) ?? collect()) : collect();

            return [
                'id'             => $br->id,
                'unit'           => $br->unit?->unit_code,
                'cluster'        => $br->unit?->cluster?->name,
                'customer'       => $br->customer?->full_name,
                'customer_code'  => $br->customer?->code,
                'br_number'      => $br->br_number,
                'br_date'        => $br->booking_date?->toDateString(),
                'br_amount'      => (float) $br->amount,
                'br_status'      => $br->status,
                'bast_date'      => $bastDoc?->handed_over_at?->toDateString(),
                'ajb_date'       => $ajbDoc?->signed_at?->toDateString(),
                'ajb_notary'     => $ajbDoc?->notary_name,
                'retensi_date'   => optional($ret->sortByDesc('doc_date')->first())?->doc_date?->toDateString(),
                'retensi_amount' => (float) $ret->sum('total'),
                'utjdp_date'     => optional($utj->sortByDesc('doc_date')->first())?->doc_date?->toDateString(),
                'utjdp_amount'   => (float) $utj->sum('total'),
                'fp_date'        => $fpDoc?->issued_at?->toDateString(),
                'fp_amount'      => (float) ($fpDoc?->total ?? 0),
                'fp_number'      => $fpDoc?->fp_number,
            ];
        });

        $summary = [
            'units'         => $rows->count(),
            'with_bast'     => $rows->filter(fn ($r) => $r['bast_date'])->count(),
            'with_ajb'      => $rows->filter(fn ($r) => $r['ajb_date'])->count(),
            'with_fp'       => $rows->filter(fn ($r) => $r['fp_number'])->count(),
            'br_total'      => round($rows->sum('br_amount'), 2),
            'retensi_total' => round($rows->sum('retensi_amount'), 2),
            'utjdp_total'   => round($rows->sum('utjdp_amount'), 2),
            'fp_total'      => round($rows->sum('fp_amount'), 2),
        ];

        return [$rows, $summary];
    }

    // ── Control sub-modul: monitoring dokumen milestone Booked Revenue (BAST / AJB / Faktur Pajak) ──

    /** Status booking "SPR final" (dari Marketing & Sales) yang layak punya BAST/AJB. */
    private const FINAL_SPR_STATUSES = ['ppjb', 'active', 'lunas', 'closed'];

    /** Query dasar booking SPR final (+ relasi tampilan). */
    private function finalBookings()
    {
        return \App\Domain\Sales\Booking\Models\Booking::query()
            ->whereIn('status', self::FINAL_SPR_STATUSES)
            ->with(['customer:id,full_name,code', 'unit:id,unit_code']);
    }

    /**
     * Control BAST — gabungan dokumen BAST terbit (SUDAH) + booking SPR final tanpa BAST (BELUM).
     * Baris BELUM bisa di-"Sudah"-kan (terbitkan BAST) satuan / massal.
     */
    public function controlBast(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $docs = \App\Domain\Legal\Bast\Models\BastDocument::query()
            ->with(['booking.customer:id,full_name,code', 'booking.unit:id,unit_code'])
            ->latest('handed_over_at')->latest('id')->get();
        $doneBookingIds = $docs->pluck('booking_id')->filter()->unique()->all();
        $belum = $this->finalBookings()->whereNotIn('id', $doneBookingIds)->orderByDesc('id')->get();

        $rows = collect();
        foreach ($docs as $d) {
            $rows->push([
                'id' => $d->id, 'kind' => 'sudah', 'booking_id' => $d->booking_id, 'number' => $d->bast_number,
                'date' => $d->handed_over_at?->toDateString(), 'spr' => $d->booking?->booking_number,
                'customer' => $d->booking?->customer?->full_name, 'unit' => $d->booking?->unit?->unit_code, 'extra' => null,
            ]);
        }
        foreach ($belum as $b) {
            $rows->push([
                'id' => null, 'kind' => 'belum', 'booking_id' => $b->id, 'number' => null, 'date' => null,
                'spr' => $b->booking_number, 'customer' => $b->customer?->full_name, 'unit' => $b->unit?->unit_code, 'extra' => null,
            ]);
        }
        $rows = $this->filterDocRows($rows, $request->input('search'));

        return $this->renderDocControl($request, 'bast', 'Detail BAST', 'Berita Acara Serah Terima (serah terima unit)', null, $rows, $belum,
            'bs.booked-revenue.control.bast.issue', false);
    }

    /**
     * Control AJB — gabungan dokumen AJB terbit (SUDAH) + booking SPR final tanpa AJB (BELUM).
     * Baris BELUM bisa di-"Sudah"-kan (terbitkan AJB, wajib lunas) satuan / massal.
     */
    public function controlAjb(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $docs = \App\Domain\Legal\Ajb\Models\AjbDocument::query()
            ->with(['booking.customer:id,full_name,code', 'booking.unit:id,unit_code'])
            ->latest('signed_at')->latest('id')->get();
        $doneBookingIds = $docs->pluck('booking_id')->filter()->unique()->all();
        $belum = $this->finalBookings()->whereNotIn('id', $doneBookingIds)->orderByDesc('id')->get();

        $rows = collect();
        foreach ($docs as $d) {
            $rows->push([
                'id' => $d->id, 'kind' => 'sudah', 'booking_id' => $d->booking_id, 'number' => $d->ajb_number,
                'date' => $d->signed_at?->toDateString(), 'spr' => $d->booking?->booking_number,
                'customer' => $d->booking?->customer?->full_name, 'unit' => $d->booking?->unit?->unit_code, 'extra' => $d->notary_name,
            ]);
        }
        foreach ($belum as $b) {
            $rows->push([
                'id' => null, 'kind' => 'belum', 'booking_id' => $b->id, 'number' => null, 'date' => null,
                'spr' => $b->booking_number, 'customer' => $b->customer?->full_name, 'unit' => $b->unit?->unit_code, 'extra' => null,
            ]);
        }
        $rows = $this->filterDocRows($rows, $request->input('search'));

        return $this->renderDocControl($request, 'ajb', 'Detail AJB', 'Akta Jual Beli (akta notaris)', 'Notaris', $rows, $belum,
            'bs.booked-revenue.control.ajb.issue', true);
    }

    /** Filter baris control (no dokumen / SPR / customer / unit). */
    private function filterDocRows($rows, ?string $search)
    {
        if ($search) {
            $rows = $rows->filter(fn ($r) => stripos(implode(' ', [$r['number'], $r['spr'], $r['customer'], $r['unit']]), $search) !== false);
        }
        // BELUM di atas (perlu tindakan), lalu SUDAH.
        return $rows->sortBy(fn ($r) => $r['kind'] === 'belum' ? 0 : 1)->values();
    }

    /** Render Control BAST/AJB (union) + data modal Input Massal. */
    private function renderDocControl(Request $request, string $type, string $title, string $subtitle, ?string $extraLabel, $rows, $belum, string $issueRoute, bool $needsNotary): Response
    {
        return Inertia::render('BusinessSupport/BookedRevenue/Control', [
            'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'extraLabel' => $extraLabel, 'extraIsMoney' => false,
            'rows' => ['data' => $rows->values(), 'links' => []],
            'filters' => $request->only(['search']),
            'summary' => ['total' => $rows->count(), 'byStatus' => ['sudah' => $rows->where('kind', 'sudah')->count(), 'belum' => $rows->where('kind', 'belum')->count()]],
            'belumBookings' => $belum->map(fn ($b) => ['booking_id' => $b->id, 'spr' => $b->booking_number, 'unit' => $b->unit?->unit_code, 'customer' => $b->customer?->full_name])->values(),
            'issueRoute' => $issueRoute,
            'issueNeedsNotary' => $needsNotary,
        ]);
    }

    /** Control Faktur Pajak — daftar FP per customer/booking. */
    public function controlFakturPajak(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);
        $base = fn () => \App\Domain\Finance\FakturPajak\Models\FakturPajak::query();
        $rows = $base()
            ->with(['customer:id,full_name,code', 'booking.unit:id,unit_code'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('fp_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                ->orWhereHas('booking.unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->latest('issued_at')->latest('id')->paginate(50)->withQueryString();
        $rows->getCollection()->transform(fn ($r) => [
            'id' => $r->id, 'number' => $r->fp_number, 'date' => $r->issued_at?->toDateString(),
            'customer' => $r->customer?->full_name, 'unit' => $r->booking?->unit?->unit_code,
            'status' => $r->status, 'extra' => (float) $r->total,
        ]);

        return $this->renderControl($request, 'fp', 'Control Faktur Pajak', 'Faktur Pajak (DPP, PPN & total)', 'Nilai (Total)', $rows, $base(), true);
    }

    /** Render halaman control monitoring generik (dipakai BAST / AJB / Faktur Pajak). */
    private function renderControl(Request $request, string $type, string $title, string $subtitle, ?string $extraLabel, $rows, $baseQuery, bool $extraIsMoney = false): Response
    {
        $byStatus = $baseQuery->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('BusinessSupport/BookedRevenue/Control', [
            'type'         => $type,
            'title'        => $title,
            'subtitle'     => $subtitle,
            'extraLabel'   => $extraLabel,
            'extraIsMoney' => $extraIsMoney,
            'rows'         => $rows,
            'filters'      => $request->only(['search', 'status']),
            'summary'      => ['total' => (int) $byStatus->sum(), 'byStatus' => $byStatus],
        ]);
    }

    /** Terbitkan BAST (Sudah) utk booking terpilih — satuan / massal (Input Massal). */
    public function issueBast(Request $request, \App\Domain\Legal\Bast\Actions\IssueBast $issue): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);
        $v = $request->validate([
            'booking_ids'   => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['integer', 'exists:bookings,id'],
            'date'          => ['nullable', 'date'],
        ]);
        $date = $v['date'] ?? now()->toDateString();
        $ok = 0;
        $skip = 0;
        foreach (array_unique($v['booking_ids']) as $bid) {
            $b = \App\Domain\Sales\Booking\Models\Booking::find($bid);
            if (! $b || \App\Domain\Legal\Bast\Models\BastDocument::where('booking_id', $bid)->exists()) {
                $skip++;
                continue;
            }
            $issue->execute($b, $date);
            $ok++;
        }

        return back()->with($ok ? 'success' : 'warning', "$ok BAST diterbitkan (Sudah).".($skip ? " $skip dilewati (sudah ada)." : ''));
    }

    /** Terbitkan AJB (Sudah) utk booking terpilih — satuan / massal. Wajib lunas (dicek IssueAjb). */
    public function issueAjb(Request $request, \App\Domain\Legal\Ajb\Actions\IssueAjb $issue): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);
        $v = $request->validate([
            'booking_ids'   => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['integer', 'exists:bookings,id'],
            'date'          => ['nullable', 'date'],
            'notary_name'   => ['nullable', 'string', 'max:150'],
        ]);
        $date = $v['date'] ?? now()->toDateString();
        $notary = trim((string) ($v['notary_name'] ?? '')) ?: '-';
        $ok = 0;
        $skip = 0;
        $fail = [];
        foreach (array_unique($v['booking_ids']) as $bid) {
            $b = \App\Domain\Sales\Booking\Models\Booking::find($bid);
            if (! $b || \App\Domain\Legal\Ajb\Models\AjbDocument::where('booking_id', $bid)->exists()) {
                $skip++;
                continue;
            }
            try {
                $issue->execute($b, $notary, $date);
                $ok++;
            } catch (BusinessRuleException $e) {
                $fail[] = $b->booking_number;
            }
        }
        $msg = "$ok AJB diterbitkan (Sudah).".($skip ? " $skip dilewati (sudah ada)." : '').($fail ? ' Gagal (belum lunas): '.implode(', ', array_slice($fail, 0, 8)).'.' : '');

        return back()->with($ok ? 'success' : ($fail ? 'error' : 'warning'), $msg);
    }

    /** Belum Booked Revenue — booking SPR final yg sudah BAST ATAU AJB, tapi belum di-booking revenue. */
    public function belumBooked(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $bookedUnitIds = BsBookedRevenue::whereIn('status', ['booked', 'recognized'])
            ->whereNotNull('unit_id')->distinct()->pluck('unit_id')->all();

        $bast = \App\Domain\Legal\Bast\Models\BastDocument::get(['booking_id', 'handed_over_at'])->keyBy('booking_id');
        $ajb  = \App\Domain\Legal\Ajb\Models\AjbDocument::get(['booking_id', 'signed_at'])->keyBy('booking_id');
        $bookingIds = $bast->keys()->merge($ajb->keys())->unique()->values();

        $bookings = \App\Domain\Sales\Booking\Models\Booking::whereIn('id', $bookingIds)
            ->whereNotIn('unit_id', $bookedUnitIds)
            ->with(['customer:id,full_name,code', 'unit:id,unit_code'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('booking_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->orderByDesc('id')->get();

        $rows = $bookings->values()->map(fn ($b, $i) => [
            'no'         => $i + 1,
            'booking_id' => $b->id,
            'unit_id'    => $b->unit_id,
            'spr'        => $b->booking_number,
            'unit'       => $b->unit?->unit_code,
            'customer'   => $b->customer?->full_name,
            'ajb_date'   => $ajb->get($b->id)?->signed_at?->toDateString(),
            'bast_date'  => $bast->get($b->id)?->handed_over_at?->toDateString(),
        ]);

        return Inertia::render('BusinessSupport/BookedRevenue/BelumBooked', [
            'rows'    => $rows,
            'filters' => $request->only(['search']),
        ]);
    }
}
