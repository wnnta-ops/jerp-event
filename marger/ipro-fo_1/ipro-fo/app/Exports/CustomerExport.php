<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

class CustomerExport extends CsvExport
{
    public function __construct(private readonly array $filters = []) {}

    public function query(): Builder
    {
        return Customer::query()
            ->when($this->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('id');
    }

    public function headings(): array
    {
        return ['Kode', 'Nama Lengkap', 'KTP', 'HP', 'Email', 'Kota', 'Status', 'Tgl Daftar'];
    }

    public function map(mixed $row): array
    {
        return [
            $row->code,
            $row->full_name,
            substr((string) $row->id_number, 0, 4).'****',
            $row->phone,
            $row->email,
            $row->city,
            $row->status?->value,
            $row->created_at?->format('Y-m-d'),
        ];
    }
}
