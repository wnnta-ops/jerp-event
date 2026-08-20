<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base exporter ke Microsoft Excel (.xlsx) ASLI — tanpa dependency Maatwebsite/PhpSpreadsheet.
 *
 * Menulis paket OOXML minimal langsung via ZipArchive (ekstensi zip sudah dipakai
 * tooling xlsx project). Sel angka ditulis sebagai numeric (bisa di-SUM di Excel),
 * sel lain sebagai inline string ber-UTF-8 (karakter Indonesia aman).
 *
 * Subclass cukup implement: query(), headings(), map($row) — sama seperti CsvExport.
 */
abstract class XlsxExport
{
    /** @return Builder<\Illuminate\Database\Eloquent\Model> */
    abstract public function query(): Builder;

    /** @return array<int, string> */
    abstract public function headings(): array;

    /** @return array<int, mixed> */
    abstract public function map(mixed $row): array;

    /**
     * Baris-baris header (bisa lebih dari satu untuk header bertingkat/grouped).
     * Default: satu baris dari headings(). Override untuk header 2 baris.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function headerRows(): array
    {
        return [$this->headings()];
    }

    /**
     * Rentang merge cell (mis. 'A1:A2', 'G1:H1') untuk header grouped. Default: tidak ada.
     *
     * @return array<int, string>
     */
    protected function merges(): array
    {
        return [];
    }

    /** Nama sheet di dalam workbook. */
    protected string $sheetName = 'Report';

    /**
     * Bangun file .xlsx dan kirim sebagai unduhan.
     *
     * Penyusunan berkasnya diserahkan ke SheetXlsx — penulis yang sama dengan yang dipakai
     * laporan keuangan, supaya hanya ada SATU tempat yang tahu cara menyusun berkas xlsx.
     */
    public function toResponse(string $filename): StreamedResponse
    {
        // Kumpulkan baris: header (bisa multi-baris) + data (chunk agar hemat memori).
        $headerRows  = $this->headerRows();
        $headerCount = count($headerRows);
        $rows        = $headerRows;
        $this->query()->chunk(500, function ($chunk) use (&$rows): void {
            foreach ($chunk as $row) {
                $rows[] = array_map(
                    fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
                    $this->map($row),
                );
            }
        });

        return (new SheetXlsx($rows, $headerCount, $this->sheetName, $this->merges()))
            ->toResponse($filename);
    }
}
