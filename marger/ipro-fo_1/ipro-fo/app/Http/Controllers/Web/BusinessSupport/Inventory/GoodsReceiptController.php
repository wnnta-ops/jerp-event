<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Inventory;

use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsStockTransaction;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\BusinessSupport\Services\StockService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $rows = BsStockTransaction::query()
            ->where('trx_type', 'receipt')
            ->with(['warehouse:id,code,name', 'supplier:id,code,name'])
            ->withCount('lines')
            ->latest('trx_date')->latest('id')
            ->paginate(50);

        return Inertia::render('BusinessSupport/Inventory/GoodsReceipts/Index', ['receipts' => $rows]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        // Penerimaan HARUS berasal dari PO supaya nominalnya mengikuti PO. PO yang sudah
        // diterima seluruhnya tidak ditawarkan lagi — memilihnya hanya berujung baris kosong.
        $pos = BsPurchaseOrder::query()
            ->where('status', 'approved')
            ->with(['supplier:id,code,name', 'lines.item:id,code,name'])
            ->latest('po_date')->limit(50)->get()
            ->map(fn ($po) => [
                'id' => $po->id, 'po_number' => $po->po_number, 'supplier_id' => $po->supplier_id,
                'supplier' => $po->supplier?->name,
                'lines' => $po->lines
                    ->filter(fn ($l) => (float) $l->qty - (float) $l->qty_received > 0.0000001)
                    ->map(fn ($l) => [
                        'item_id' => $l->item_id, 'item' => $l->item?->code.' - '.$l->item?->name,
                        'qty' => (float) $l->qty, 'qty_received' => (float) $l->qty_received,
                        'outstanding' => round((float) $l->qty - (float) $l->qty_received, 4), 'unit_price' => (float) $l->unit_price,
                    ])->values(),
            ])
            ->filter(fn ($po) => $po['lines']->isNotEmpty())
            ->values();

        return Inertia::render('BusinessSupport/Inventory/GoodsReceipts/Form', [
            'warehouses' => BsWarehouse::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'purchaseOrders' => $pos,
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'warehouse_id'      => ['required', 'integer', 'exists:bs_warehouses,id'],
            // Penerimaan manual / non-PO ditiadakan: nominalnya wajib mengikuti PO.
            'purchase_order_id' => ['required', 'integer', 'exists:bs_purchase_orders,id'],
            'trx_date'          => ['required', 'date'],
            'reference'         => ['nullable', 'string', 'max:150'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer', 'exists:bs_items,id'],
            'lines.*.qty'        => ['required', 'numeric', 'min:0.01'],
        ], [
            // Aplikasi ini belum memuat terjemahan validasi bawaan, jadi pesannya ditulis
            // langsung supaya operator tidak melihat "validation.required".
            'purchase_order_id.required' => 'Pilih PO terlebih dahulu — penerimaan barang harus berasal dari Purchase Order.',
            'purchase_order_id.exists'   => 'PO yang dipilih tidak ditemukan.',
            'warehouse_id.required'      => 'Gudang wajib dipilih.',
            'trx_date.required'          => 'Tanggal terima wajib diisi.',
            'lines.required'             => 'Belum ada barang yang diterima.',
            'lines.min'                  => 'Belum ada barang yang diterima.',
            'lines.*.qty.min'            => 'Qty terima harus lebih dari 0.',
        ]);

        $po = BsPurchaseOrder::with('lines')->findOrFail((int) $v['purchase_order_id']);
        if ($po->status !== 'approved') {
            throw ValidationException::withMessages([
                'purchase_order_id' => "PO {$po->po_number} berstatus {$po->status} — hanya PO yang sudah disetujui yang bisa diterima barangnya.",
            ]);
        }

        // Sisa yang masih boleh diterima per item, dari PO-nya sendiri.
        $sisa = [];
        $harga = [];
        foreach ($po->lines as $l) {
            $sisa[(int) $l->item_id] = ($sisa[(int) $l->item_id] ?? 0) + ((float) $l->qty - (float) $l->qty_received);
            $harga[(int) $l->item_id] = (float) $l->unit_price;
        }

        // Harga TIDAK diambil dari kiriman peramban — selalu dari baris PO, supaya nominal
        // penerimaan tidak mungkin menyimpang dari nominal yang dipesan.
        $lines = [];
        foreach ($v['lines'] as $i => $l) {
            $itemId = (int) $l['item_id'];
            if (! array_key_exists($itemId, $harga)) {
                throw ValidationException::withMessages([
                    "lines.{$i}.item_id" => "Item ini tidak ada pada PO {$po->po_number}.",
                ]);
            }
            if ((float) $l['qty'] > $sisa[$itemId] + 0.0000001) {
                throw ValidationException::withMessages([
                    "lines.{$i}.qty" => 'Melebihi sisa PO — sisa yang belum diterima '.rtrim(rtrim(number_format($sisa[$itemId], 2, ',', '.'), '0'), ',').'.',
                ]);
            }
            $sisa[$itemId] -= (float) $l['qty'];
            $lines[] = ['item_id' => $itemId, 'qty' => (float) $l['qty'], 'unit_price' => $harga[$itemId]];
        }

        $trx = DB::transaction(function () use ($v, $po, $lines, $stock, $request) {
            $trx = BsStockTransaction::create([
                'trx_number'        => $stock->nextNumber('RCV', BsStockTransaction::class, 'trx_date'),
                'trx_type'          => 'receipt',
                'trx_date'          => $v['trx_date'],
                'warehouse_id'      => $v['warehouse_id'],
                // Supplier mengikuti PO, bukan pilihan bebas — dokumennya harus sepasang.
                'supplier_id'       => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'reference'         => $v['reference'] ?? null,
                'notes'             => $v['notes'] ?? null,
                'created_by'        => $request->user()->id,
            ]);
            foreach ($lines as $l) {
                $trx->lines()->create($l);
            }
            $stock->post($trx);
            app(\App\Domain\BusinessSupport\Actions\PostInventoryJournal::class)->execute($trx); // jurnal GL otomatis
            return $trx;
        });

        $journal = \App\Domain\BusinessSupport\Models\BsJournal::where('source', 'inventory')->where('reference', $trx->trx_number)->latest('id')->first();
        $note = $journal ? " · jurnal {$journal->journal_number} terbentuk (Dr Persediaan / Cr Hutang)" : '';

        return redirect()->route('bs.receipt.index')->with('success', "Penerimaan {$trx->trx_number} tercatat — stok bertambah{$note}.");
    }
}
