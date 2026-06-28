<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base CSV exporter — streaming, tanpa dependency Maatwebsite/Excel.
 *
 * CSV dengan UTF-8 BOM agar Excel membaca karakter Indonesia dengan benar,
 * dan menggunakan chunking agar hemat memori untuk dataset besar.
 *
 * Subclass cukup implement: query(), headings(), map($row).
 */
abstract class CsvExport
{
    /** @return Builder<\Illuminate\Database\Eloquent\Model> */
    abstract public function query(): Builder;

    /** @return array<int, string> */
    abstract public function headings(): array;

    /** @return array<int, mixed> */
    abstract public function map(mixed $row): array;

    /**
     * Stream the query result as a downloadable CSV file.
     */
    public function toResponse(string $filename): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ];

        return new StreamedResponse(function (): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM supaya Excel baca diakritik/karakter Indonesia dengan benar.
            fwrite($out, "\xEF\xBB\xBF");

            // Header row — escape param eksplisit '' (PHP 8.4-safe)
            fputcsv($out, $this->headings(), ',', '"', '');

            // Data rows (chunked untuk hemat memori)
            $this->query()->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(
                        fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
                        $this->map($row),
                    ), ',', '"', '');
                }
            });

            fclose($out);
        }, 200, $headers);
    }
}
