<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Builder;

class BookingExport extends CsvExport
{
    public function __construct(private readonly array $filters = []) {}

    public function query(): Builder
    {
        return Booking::query()
            ->with(['customer:id,full_name', 'unit:id,unit_code'])
            ->when($this->filters['status']    ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($this->filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('booking_date', '>=', $v))
            ->when($this->filters['date_to']   ?? null, fn ($q, $v) => $q->whereDate('booking_date', '<=', $v))
            ->orderBy('booking_date');
    }

    public function headings(): array
    {
        return ['Nomor Booking', 'Tanggal', 'Customer', 'Unit', 'Harga Dasar', 'Diskon %', 'PPN', 'Total', 'Status'];
    }

    public function map(mixed $row): array
    {
        return [
            $row->booking_number,
            $row->booking_date?->format('Y-m-d'),
            $row->customer?->full_name,
            $row->unit?->unit_code,
            $row->base_price,
            $row->discount_pct,
            $row->ppn_amount,
            $row->total_price,
            $row->status?->value,
        ];
    }
}
