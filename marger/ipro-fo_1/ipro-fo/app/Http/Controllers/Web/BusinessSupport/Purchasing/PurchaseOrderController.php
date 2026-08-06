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

        // Rincian & perilakunya disamakan dengan daftar Invoice Hutang (Account Payable):
        // SELURUH baris dikirim ke klien, lalu pencarian global, filter per-kolom (ketik +
        // checklist), ringkasan, dan ekspor Excel dihitung di sisi klien agar kotak ringkasan
        // selalu mengikuti hasil filter — persis seperti Payable/Index.vue.
        $rows = BsPurchaseOrder::query()
            ->with(['supplier:id,code,name', 'project:id,name'])
            ->withCount('lines')
            ->latest('po_date')->latest('id')
            ->get()
            ->map(fn (BsPurchaseOrder $r) => [
                'id'          => $r->id,
                'po_number'   => $r->po_number,
                'po_date'     => $r->po_date?->toDateString(),
                'due_date'    => $r->due_date?->toDateString(),
                'supplier'    => $r->supplier ? ['name' => $r->supplier->name] : null,
                'project'     => $r->project?->name,
                'po_type'     => $r->po_type,
                'notes'       => $r->notes,
                'lines_count' => (int) $r->lines_count,
                'subtotal'    => round((float) $r->subtotal, 2),
                'ppn_amount'  => round((float) $r->ppn_amount, 2),
                'total'       => round((float) $r->total, 2),
                'status'      => $r->status,
                'printed_at'  => $r->printed_at?->toDateTimeString(),
                'print_count' => (int) $r->print_count,
            ]);

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Index', [
            'orders' => $rows,
            // FPB yang SUDAH DISETUJUI tapi belum dijadikan PO — muncul di halaman ini agar
            // permintaan yang lolos approval langsung bisa diteruskan jadi Purchase Order.
            'pendingFpb' => $this->pendingFpb(),
        ]);
    }

    /**
     * FPB berstatus approved yang belum punya PO.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pendingFpb(): array
    {
        $used = BsPurchaseOrder::whereNotNull('material_request_id')->pluck('material_request_id')->all();

        return \App\Domain\BusinessSupport\Models\BsMaterialRequest::query()
            ->where('status', 'approved')
            ->whereNotIn('id', $used)
            ->with(['lines.item:id,code,name'])
            ->latest('request_date')->latest('id')->limit(100)->get()
            ->map(fn ($mr) => [
                'id'         => $mr->id,
                'number'     => $mr->mr_number,
                'date'       => $mr->request_date?->toDateString(),
                'notes'      => $mr->notes,
                'line_count' => $mr->lines->count(),
                'items'      => $mr->lines->map(fn ($l) => trim(((string) $l->item?->code ? $l->item->code.' - ' : '').(string) $l->item?->name))->filter()->take(3)->values()->all(),
            ])->values()->all();
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        // Datang dari FPB yang disetujui → baris PO diisi awal dari baris FPB-nya.
        $fpb = null;
        if ($id = (int) $request->integer('fpb')) {
            $mr = \App\Domain\BusinessSupport\Models\BsMaterialRequest::with('lines.item:id,code,name')->find($id);
            if ($mr && $mr->status === 'approved') {
                $fpb = [
                    'id'     => $mr->id,
                    'number' => $mr->mr_number,
                    'notes'  => $mr->notes,
                    'lines'  => $mr->lines->map(fn ($l) => [
                        'item_id' => $l->item_id,
                        'qty'     => (float) $l->qty,
                        'spec'    => $l->spec,
                    ])->values()->all(),
                ];
            }
        }

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Form', [
            'suppliers' => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'items'     => BsItem::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'projects'  => Project::orderBy('name')->get(['id', 'name']),
            'ppn_default' => (float) config('domain.tax.ppn_default_pct', 11),
            'fpb'       => $fpb,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'supplier_id'  => ['required', 'integer', 'exists:bs_suppliers,id'],
            'project_id'   => ['nullable', 'integer', 'exists:projects,id'],
            'material_request_id' => ['nullable', 'integer', 'exists:bs_material_requests,id'],
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
                // Tautan ke FPB asalnya: sekali dipakai, FPB itu hilang dari daftar "siap jadi PO".
                'material_request_id' => $v['material_request_id'] ?? null,
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

    // ─────────────────────────── CETAK PURCHASE ORDER ───────────────────────────
    // Format mengikuti lampiran PO-JI-2026-07-0074: kop MUP, blok Nomor/Sistem Pembayaran/
    // Kepada Yth + Proyek/Kirim Ke/Tanggal Order/Pelaksanaan/Penerima, tabel barang, ringkasan
    // Subtotal/PPN/TOTAL, Catatan, KONDISI PEMBELIAN, dan 3 kolom tanda tangan.
    // Halaman cetak BISA DIEDIT; hasil editnya disimpan per-PO di kolom JSON print_meta.

    /**
     * Pratinjau + cetak satu atau BANYAK PO sekaligus (dipanggil dari daftar Invoice Hutang
     * yang baris-barisnya dicentang). ids = daftar id PO dipisah koma.
     */
    public function print(Request $request): Response
    {
        // Modul 'ap' ikut diizinkan: pintu masuk utamanya adalah tombol "Cetak PO" pada daftar
        // Invoice Hutang (halaman bermodul 'ap'). Tanpa ini, petugas AP yang tidak punya modul
        // 'bs' akan menerima 403 di tab baru tanpa penjelasan. Ini jalur BACA/CETAK saja.
        abort_unless($this->canPrint($request), 403);

        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();
        abort_if($ids->isEmpty(), 404, 'Tidak ada Purchase Order yang dipilih.');

        $orders = BsPurchaseOrder::with(['lines.item.uom', 'supplier', 'project'])
            ->whereIn('id', $ids)->orderBy('po_date')->orderBy('id')->get();
        abort_if($orders->isEmpty(), 404, 'Purchase Order tidak ditemukan.');

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Print', [
            'orders' => $orders->map(fn (BsPurchaseOrder $po) => $this->printPayload($po))->values(),
        ]);
    }

    /**
     * Boleh melihat & mencetak PO: modul Purchasing ('bs') ATAU Account Payable ('ap'),
     * karena cetak PO dipicu dari kedua modul tersebut. Menyimpan format tetap khusus 'bs'.
     */
    private function canPrint(Request $request): bool
    {
        $u = $request->user();

        return (bool) ($u?->canModule('bs') || $u?->canModule('ap'));
    }

    /** Susun data cetak satu PO: nilai bawaan dari data PO, ditimpa hasil edit (print_meta). */
    private function printPayload(BsPurchaseOrder $po): array
    {
        $co       = (array) config('business_support.company');
        $coName   = (string) ($co['name'] ?? 'PT. MITRA USAHA PROPERTINDO');
        $supplier = $po->supplier;

        $items = $po->lines->map(function ($l) {
            $item = $l->item;
            $qty  = (float) $l->qty;

            return [
                'nama'  => trim((($item?->code) ? $item->code.' - ' : '').($item?->name ?? '')),
                'sat'   => $item?->uom?->code ?? $item?->uom?->name ?? '',
                'qty'   => $qty,
                'harga' => (float) $l->unit_price,
                'jumlah' => round($qty * (float) $l->unit_price, 2),
            ];
        })->values()->all();

        $subtotal = round((float) collect($items)->sum('jumlah'), 2);
        $ppnPct   = (float) $po->ppn_pct;
        $ppn      = round($subtotal * $ppnPct / 100, 2);

        // Nilai bawaan (dari data) — semuanya boleh diubah operator di halaman cetak.
        $default = [
            'nomor'             => $po->po_number,
            'sistem_pembayaran' => $po->term_payment ?: 'Term Of Payment (TOP)',
            'kepada'            => $supplier?->name ?? '',
            'kepada_alamat'     => trim(implode(', ', array_filter([$supplier?->address, $supplier?->city]))),
            'proyek'            => $po->project?->name ?? '',
            'kirim_ke'          => $coName,
            'kirim_alamat'      => (string) ($co['address'] ?? ''),
            'tanggal_order'     => $po->po_date?->translatedFormat('d F Y') ?? '',
            'pelaksanaan'       => $coName,
            'penerima'          => '',
            'items'             => $items,
            'subtotal'          => $subtotal,
            'ppn_pct'           => $ppnPct,
            'ppn_amount'        => $ppn,
            'total'             => round($subtotal + $ppn, 2),
            'catatan'           => 'Harga Sudah termasuk PPN '.rtrim(rtrim(number_format($ppnPct, 2, ',', '.'), '0'), ',').'% & Ongkir',
            'cara_pembayaran'   => $po->term_payment ?: 'Nett 30 hari',
            'garansi'           => 'Keterangan tambahan terlampir dan menjadi satu kesatuan yang tidak terpisahkan dengan PO ini',
            'kelengkapan'       => "Kwitansi asli (≥ 5 juta bermaterai) · Invoice (1 asli)\nSurat jalan (asli) · Faktur Pajak (2 lembar)\nSurat Pesanan Barang/PO (asli)",
            'pengambilan'       => 'Pengambilan PO asli dan penyerahan tagihan di '.$coName.' RUKO AVENUE NO. 27, KAHURIPAN NIRWANA - SIDOARJO. Penyerahan berkas lengkap (invoice & faktur) setiap bulan pada periode tgl. 7, 8, 22 & 23',
            'pembayaran'        => 'Pembayaran dilakukan dengan cara transfer',
            'ttd'               => [
                ['label' => 'Dibuat Oleh,', 'nama' => '', 'jabatan' => ''],
                ['label' => 'Mengetahui,', 'nama' => '', 'jabatan' => 'General Manager'],
                ['label' => 'Disetujui,', 'nama' => (string) ($co['signer_name'] ?? ''), 'jabatan' => (string) ($co['signer_title'] ?? 'Direktur')],
            ],
        ];

        // Hasil edit yang tersimpan menimpa nilai bawaan — TAPI hanya field presentasi
        // (lihat PRINT_META_KEYS). Nomor PO, baris barang, dan seluruh angka SELALU diambil
        // ulang dari data PO agar cetakan tidak pernah membeku di nilai lama.
        $meta = collect(is_array($po->print_meta) ? $po->print_meta : [])
            ->only(self::PRINT_META_KEYS)->all();

        return [
            'id'          => $po->id,
            'po_number'   => $po->po_number,
            'printed_at'  => $po->printed_at?->toDateTimeString(),
            'print_count' => (int) $po->print_count,
            'form'        => array_replace($default, $meta),
        ];
    }

    /**
     * Field format cetak yang BOLEH disimpan (murni presentasi/keterangan).
     *
     * Sengaja TIDAK memuat: nomor, items, subtotal, ppn_pct, ppn_amount, total. Angka & baris
     * barang harus selalu mengikuti data PO yang berlaku — kalau ikut disimpan, PO yang direvisi
     * (bs.po.revise / adjust-ppn) akan tetap tercetak dengan baris & total lama, dan nomor PO
     * bisa ditulis ulang jadi berbeda dari record. Operator tetap bebas mengubahnya di layar
     * untuk cetakan saat itu; yang tidak diizinkan hanyalah membekukannya untuk selamanya.
     */
    private const PRINT_META_KEYS = [
        'sistem_pembayaran', 'kepada', 'kepada_alamat', 'proyek', 'kirim_ke', 'kirim_alamat',
        'tanggal_order', 'pelaksanaan', 'penerima', 'catatan', 'cara_pembayaran', 'garansi',
        'kelengkapan', 'pengambilan', 'pembayaran', 'ttd',
    ];

    /** Simpan hasil edit format cetak (print_meta) — agar cetakan berikutnya tetap sama. */
    public function savePrintFormat(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        $form = $request->validate(['form' => ['required', 'array']])['form'];
        // Hanya field presentasi yang dipersistensi (lihat PRINT_META_KEYS).
        $po->update(['print_meta' => collect($form)->only(self::PRINT_META_KEYS)->all()]);

        return back()->with('success', "Format cetak PO {$po->po_number} disimpan.");
    }

    /**
     * Catat bahwa PO benar-benar DICETAK (bukan sekadar dibuka) — sumber kolom
     * "waktu cetak PO" pada Report PO.
     */
    public function markPrinted(Request $request): RedirectResponse
    {
        // Sama seperti print(): petugas AP juga mencetak, jadi jejak cetaknya harus ikut tercatat.
        abort_unless($this->canPrint($request), 403);
        $ids = collect($request->input('ids', []))->map(fn ($v) => (int) $v)->filter()->unique();
        if ($ids->isEmpty()) {
            return back();
        }
        BsPurchaseOrder::whereIn('id', $ids)->update([
            'printed_at'  => now(),
            'printed_by'  => $request->user()->id,
            'print_count' => DB::raw('print_count + 1'),
        ]);

        return back();
    }

    // ─────────────────────────── REPORT PO ───────────────────────────

    /** Laporan jumlah PO per supplier + rincian nomor PO, waktu dibuat, dan waktu dicetak. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $orders = BsPurchaseOrder::query()
            ->with(['supplier:id,code,name', 'project:id,name'])
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('po_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('po_date', '<=', $v))
            ->orderBy('po_date')->orderBy('id')
            ->get();

        // Kelompokkan per supplier: jumlah PO + nilai + rincian tiap nomor PO.
        $groups = $orders->groupBy(fn ($po) => $po->supplier_id ?? 0)
            ->map(function ($list) {
                $first = $list->first();

                return [
                    'supplier_id'   => $first->supplier_id,
                    'supplier_code' => $first->supplier?->code,
                    'supplier'      => $first->supplier?->name ?? '(tanpa supplier)',
                    'po_count'      => $list->count(),
                    'total'         => round((float) $list->sum(fn ($p) => (float) $p->total), 2),
                    'printed_count' => $list->filter(fn ($p) => $p->printed_at !== null)->count(),
                    'orders'        => $list->map(fn ($p) => [
                        'id'          => $p->id,
                        'po_number'   => $p->po_number,
                        'po_date'     => $p->po_date?->toDateString(),
                        'project'     => $p->project?->name,
                        'status'      => $p->status,
                        'total'       => round((float) $p->total, 2),
                        'created_at'  => $p->created_at?->toDateTimeString(),   // waktu pembuatan PO
                        'printed_at'  => $p->printed_at?->toDateTimeString(),   // waktu cetak PO
                        'print_count' => (int) $p->print_count,
                    ])->values(),
                ];
            })
            ->sortByDesc('po_count')->values();

        return Inertia::render('BusinessSupport/Purchasing/PurchaseOrders/Report', [
            'groups'  => $groups,
            'filters' => $request->only(['from', 'to']),
            'summary' => [
                'suppliers' => $groups->count(),
                'po_count'  => $orders->count(),
                'total'     => round((float) $orders->sum(fn ($p) => (float) $p->total), 2),
                'printed'   => $orders->filter(fn ($p) => $p->printed_at !== null)->count(),
            ],
        ]);
    }
}
