<?php

namespace App\Modules\FO\Adminjual\Imports;

use App\Modules\FO\Adminjual\Support\UnitCodeParser;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import koordinat polygon per unit untuk site plan (sheet KOORDINAT).
 * Kolom: No. Unit (JRK3-004-XVI-1), Peta (nama siteplan/cluster, opsional),
 *        Koordinat ("x1,y1,x2,y2,...,xn,yn" — comma-separated)
 *
 * Polygon disimpan di fo_units.siteplan_coords sebagai array
 * [[x1,y1], [x2,y2], ...]. Format ini langsung dipakai Leaflet
 * (CRS.Simple) untuk render polygon overlay.
 */
class KoordinatImport implements ToCollection, WithHeadingRow
{
    use Importable;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int,string> */
    public array $errors = [];

    public function __construct(private readonly int $tenantId = 1) {}

    public function headingRow(): int
    {
        return 1;
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $idx => $row) {
            $code = trim((string) ($row['no_unit'] ?? $row['no'] ?? ''));
            $raw = trim((string) ($row['koordinat'] ?? $row['coords'] ?? ''));

            if ($code === '' || $raw === '') {
                $this->skipped++;

                continue;
            }

            $unit = UnitCodeParser::findUnit($code, $this->tenantId);
            if (! $unit) {
                $this->errors[] = 'Row '.($idx + 2).': Unit "'.$code.'" tidak ditemukan.';
                $this->skipped++;

                continue;
            }

            // Parse "3096,1182,3204,1192,..." → [[3096,1182], [3204,1192], ...]
            $nums = array_values(array_filter(array_map(
                fn ($v) => is_numeric($v = trim($v)) ? (float) $v : null,
                explode(',', $raw)
            ), fn ($v) => $v !== null));

            if (count($nums) < 6 || count($nums) % 2 !== 0) {
                $this->errors[] = 'Row '.($idx + 2).' ('.$code.'): koordinat tidak valid (perlu minimal 3 titik, jumlah angka harus genap).';
                $this->skipped++;

                continue;
            }

            $polygon = [];
            for ($i = 0; $i < count($nums); $i += 2) {
                $polygon[] = [$nums[$i], $nums[$i + 1]];
            }

            try {
                $unit->update(['siteplan_coords' => $polygon]);
                $this->updated++;
            } catch (\Throwable $e) {
                $this->errors[] = 'Row '.($idx + 2).' ('.$code.'): '.$e->getMessage();
            }
        }
    }
}
