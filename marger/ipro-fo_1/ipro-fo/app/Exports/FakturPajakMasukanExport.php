<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Export Faktur Pajak Masukan (PPN atas pembelian / AP) ke Microsoft Excel (.xlsx) ASLI.
 *
 * Rincian = invoice AP ber-PPN pada masa pajak (year/month). NPWP diambil dari supplier.
 * Kolom: No. Faktur Pajak, Tanggal, NPWP, Nama Supplier, No. Inv. Supplier, DPP, PPN.
 */
class FakturPajakMasukanExport extends XlsxExport
{
    protected string $sheetName = 'Faktur Masukan';

    public function __construct(
        private readonly int $year,
        private readonly int $month,
    ) {}

    public function query(): Builder
    {
        return BsApInvoice::query()
            ->with('supplier:id,name,npwp')
            ->where('ppn_amount', '>', 0)
            ->whereYear('invoice_date', $this->year)
            ->whereMonth('invoice_date', $this->month)
            ->orderBy('invoice_date')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return ['No. Faktur Pajak', 'Tanggal', 'NPWP', 'Nama Supplier', 'No. Inv. Supplier', 'DPP', 'PPN'];
    }

    public function map(mixed $row): array
    {
        return [
            $row->tax_invoice_no ?? '',
            $row->invoice_date?->format('Y-m-d'),
            $row->supplier?->npwp ?: '',
            $row->supplier?->name ?? '',
            $row->supplier_invoice_no ?? '',
            (float) $row->dpp,
            (float) $row->ppn_amount,
        ];
    }
}
