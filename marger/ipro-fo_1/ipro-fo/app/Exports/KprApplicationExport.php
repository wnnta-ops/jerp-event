<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Support\KprLifecycleReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Export Proses KPR ke Excel (.xlsx) dengan layout lifecycle bertingkat
 * (header 2 baris grouped) — kolom & nilainya identik dengan halaman daftar
 * karena keduanya memakai KprLifecycleReport. Filter sama dengan index().
 *
 * @param array<string, mixed> $filters
 */
class KprApplicationExport extends XlsxExport
{
    protected string $sheetName = 'Proses KPR';

    public function __construct(private readonly array $filters = []) {}

    public function query(): Builder
    {
        return KprApplication::query()
            ->with(KprLifecycleReport::eagerLoad())
            ->when(
                KprStatus::tryFrom((string) ($this->filters['status'] ?? '')),
                fn ($q, KprStatus $s) => $q->where('status', $s->value),
            )
            ->when($this->filters['search'] ?? null, fn ($q, $term) => $q->where(function ($w) use ($term): void {
                $w->where('application_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$term}%"))
                    ->orWhereHas('booking.unit', fn ($u) => $u->where('unit_code', 'like', "%{$term}%"));
            }))
            ->when((int) ($this->filters['project_id'] ?? 0), fn ($q, $pid) => $q->whereHas('booking.unit', fn ($u) => $u->where('project_id', $pid)))
            ->latest('submitted_at');
    }

    /** Baris ke-2 header (sub-kolom tiap tahap). Kolom tetap dikosongkan (di-merge dari baris 1). */
    public function headings(): array
    {
        return [
            '', '', '', '', '', '', '',                              // No.KPR, Status General, Customer, Project, Cluster, Unit, Bank
            'Nominal', 'Tanggal',                                    // Draft
            'Nama Bank', 'Nominal', 'Tanggal', 'Aging (Hari)',       // Submitted
            'Nama Bank', 'No. SP3K', 'Nominal', 'Tanggal', 'Aging (Hari)', // SP3K
            'Keputusan', 'Tanggal',                                  // Keputusan
            'Nama Bank', 'No. SP3K', 'Nominal', 'Tanggal', 'Aging (Hari)', // Akad
        ];
    }

    /** @return array<int, array<int, mixed>> Header 2 baris bertingkat. */
    protected function headerRows(): array
    {
        $row1 = [
            'No. KPR', 'Status General', 'Customer', 'Project', 'Cluster', 'Unit', 'Bank',
            'Draft', '',
            'Submitted', '', '', '',
            'SP3K', '', '', '', '',
            'Keputusan', '',
            'Akad', '', '', '', '',
        ];

        return [$row1, $this->headings()];
    }

    /** @return array<int, string> Merge: kolom tetap vertikal + judul grup horizontal. */
    protected function merges(): array
    {
        return [
            'A1:A2', 'B1:B2', 'C1:C2', 'D1:D2', 'E1:E2', 'F1:F2', 'G1:G2', // No.KPR, Status General, Customer, Project, Cluster, Unit, Bank
            'H1:I1',          // Draft
            'J1:M1',          // Submitted
            'N1:R1',          // SP3K
            'S1:T1',          // Keputusan
            'U1:Y1',          // Akad
        ];
    }

    /** @return array<int, mixed> 25 kolom datar dari KprLifecycleReport. */
    public function map(mixed $row): array
    {
        return KprLifecycleReport::flat(KprLifecycleReport::row($row));
    }
}
