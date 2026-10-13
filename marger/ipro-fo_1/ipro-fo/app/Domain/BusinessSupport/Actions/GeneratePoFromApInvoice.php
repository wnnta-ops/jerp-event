<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Services\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Terbitkan Purchase Order dari Invoice Hutang yang baru diregistrasi.
 *
 * "Simpan & Buat Jurnal" pada Registrasi Invoice Hutang sekaligus mencatat PO-nya, supaya
 * pembelian yang tagihannya masuk lebih dulu tetap punya dokumen pesanan — tidak ada lagi
 * invoice yang menggantung tanpa PO.
 *
 * Yang tidak bisa diambil dari invoice DIBANGKITKAN sistem:
 *  - No. PO           → penomoran berjalan (StockService::nextNumber)
 *  - Baris PO         → dari Detil Tagihan; bila invoice tanpa rincian, dibuat SATU baris
 *                       memakai keterangan & DPP invoice
 *  - Master Barang    → dicocokkan dengan nama yang sudah ada; kalau belum ada, item baru
 *                       dibuat otomatis berkode AUTO-xxxx (item_id tidak boleh kosong)
 *  - Termin           → dari term_days invoice
 *
 * PO-nya ditandai 'closed' + qty_received penuh: barang/jasanya memang sudah diterima —
 * tagihannya sudah masuk. Invoice ditautkan ke PO ini (source_type/source_id) supaya PO
 * tersebut TIDAK muncul lagi di Posting Invoice dan tidak bisa ditagihkan kedua kali.
 */
final class GeneratePoFromApInvoice
{
    public function __construct(private readonly StockService $stock) {}

    /** @return BsPurchaseOrder|null null bila memang tidak perlu dibuatkan PO */
    public function execute(BsApInvoice $invoice, ?int $userId = null): ?BsPurchaseOrder
    {
        // Saldo awal bukan transaksi pembelian → tidak berpasangan dengan PO.
        if ($invoice->trx_type === 'saldo_awal') {
            return null;
        }
        // Sudah bertaut ke dokumen sumber (mis. PO / SPK / batch) → jangan buat lagi (idempoten).
        if (! empty($invoice->source_type)) {
            return null;
        }
        // Reimburse karyawan & pengembalian deposit bukan pembelian → tidak berpasangan dengan PO.
        if (($invoice->jenis ?? 'invoice') !== 'invoice') {
            return null;
        }

        return DB::transaction(function () use ($invoice, $userId): ?BsPurchaseOrder {
            $lines = $this->linesFrom($invoice);
            if (! $lines) {
                return null;
            }

            $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
            $ppnPct   = (float) $invoice->ppn_pct;
            $ppnAmt   = round((float) $invoice->ppn_amount, 2);

            $po = BsPurchaseOrder::create([
                'supplier_id'  => $invoice->supplier_id,
                'project_id'   => null,
                'po_number'    => $this->stock->nextNumber('PO', BsPurchaseOrder::class, 'po_date'),
                'po_date'      => $invoice->invoice_date,
                'due_date'     => $invoice->due_date,
                'po_type'      => 'barang',
                'term_payment' => $invoice->term_days ? $invoice->term_days.' hari' : null,
                'subtotal'     => $subtotal,
                'ppn_pct'      => $ppnPct,
                'ppn_amount'   => $ppnAmt,
                'pph_pct'      => (float) $invoice->pph_pct,
                'total'        => round($subtotal + $ppnAmt, 2),
                // Barang/jasanya sudah diterima (tagihan sudah masuk) → PO langsung selesai.
                'status'       => 'closed',
                'approved_at'  => now(),
                'notes'        => trim('Dibuat otomatis dari Invoice Hutang '.$invoice->invoice_number
                    .($invoice->description ? ' — '.$invoice->description : '')),
                'created_by'   => $userId,
            ]);

            foreach ($lines as $l) {
                $po->lines()->create([
                    'item_id'      => $l['item_id'],
                    'qty'          => $l['qty'],
                    'qty_received' => $l['qty'],   // sudah diterima — invoice-nya sudah terbit
                    'unit_price'   => $l['unit_price'],
                    'amount'       => $l['amount'],
                ]);
            }

            // Tautkan invoice → PO. Ini juga yang membuat PO tsb tidak muncul lagi sebagai
            // "belum diinvoice" di Posting Invoice (anti tagih dobel).
            $invoice->update(['source_type' => 'po', 'source_id' => $po->id]);

            return $po;
        });
    }

    /**
     * Baris PO dari Detil Tagihan invoice; bila invoice tak punya rincian, satu baris dari
     * keterangan + DPP-nya.
     *
     * @return array<int,array{item_id:int,qty:float,unit_price:float,amount:float}>
     */
    private function linesFrom(BsApInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $src = $invoice->lines->map(fn ($l) => [
            'description' => (string) ($l->description ?: $invoice->description),
            'qty'         => (float) ($l->qty ?: 1),
            'unit_price'  => (float) $l->unit_price,
            'amount'      => (float) $l->amount,
        ])->filter(fn ($l) => $l['amount'] > 0.009)->values()->all();

        if (! $src) {
            $dpp = round((float) $invoice->dpp, 2);
            if ($dpp <= 0) {
                return [];
            }
            $src = [[
                'description' => (string) ($invoice->description ?: 'Pembelian'),
                'qty' => 1.0, 'unit_price' => $dpp, 'amount' => $dpp,
            ]];
        }

        $out = [];
        foreach ($src as $l) {
            $qty = $l['qty'] > 0 ? $l['qty'] : 1.0;
            // Harga satuan dihitung ulang dari nominal supaya qty × harga = nominal persis
            // (baris invoice boleh saja hanya mengisi nominal tanpa qty/harga).
            $unit = round($l['amount'] / $qty, 2);
            $out[] = [
                'item_id'    => $this->resolveItemId($l['description']),
                'qty'        => $qty,
                'unit_price' => $unit,
                'amount'     => round($l['amount'], 2),
            ];
        }

        return $out;
    }

    /**
     * Master barang untuk sebuah keterangan: pakai yang sudah ada (cocok nama, tanpa peduli
     * besar-kecil huruf), atau buat baru — item_id pada baris PO tidak boleh kosong.
     */
    private function resolveItemId(string $description): int
    {
        $name = trim($description) !== '' ? mb_substr(trim($description), 0, 255) : 'Pembelian';

        $existing = BsItem::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) BsItem::create([
            'tenant_id' => tenant_id() ?? default_tenant_id(),
            'code'      => $this->nextItemCode(),
            'name'      => $name,
            'type'      => 'stock',
            'is_active' => true,
        ])->id;
    }

    /** Kode item otomatis AUTO-0001, AUTO-0002, … (unik per tenant). */
    private function nextItemCode(): string
    {
        $last = BsItem::where('code', 'like', 'AUTO-%')->orderByRaw('LENGTH(code) DESC')->orderBy('code', 'desc')->value('code');
        $seq  = $last ? ((int) substr((string) $last, 5)) + 1 : 1;

        do {
            $code = sprintf('AUTO-%04d', $seq);
            $seq++;
        } while (BsItem::where('code', $code)->exists());

        return $code;
    }
}
