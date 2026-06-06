<?php

namespace App\Modules\FO\Adminjual\Imports;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Adminjual\Support\UnitCodeParser;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import Pricelist — FORMAT LEBAR (wide): satu baris per unit, satu kolom harga
 * per skema bayar. Contoh kolom:
 *
 *   No Unit          | Harga Cash  | Harga KPR   | Harga Inhouse
 *   JRK3-004-XV-8    | 688000000   | 720000000   | 700000000
 *
 * Tiap kolom "Harga {Skema}" dicocokkan otomatis ke skema di master (by code
 * atau name, case-insensitive). Menambah skema baru cukup: tambah skema di
 * master + tambah kolom di Excel — TANPA ubah kode.
 *
 * Setiap (unit, skema) di-upsert ke fo_unit_pricelists. Harga utama (skema
 * pertama yang tampil di site plan, fallback kolom pertama) disinkron ke
 * fo_units.harga_jual sebagai default.
 */
class PriceListImport implements ToCollection, WithHeadingRow
{
    use Importable;

    public int $created = 0;

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
        // Peta heading-kolom → skema. Dibangun sekali dari master skema aktif.
        // Key = nama kolom ter-normalisasi (snake) milik Maatwebsite heading row.
        $skemas = Skema::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->get(['id', 'code', 'name']);

        if ($skemas->isEmpty()) {
            $this->errors[] = 'Belum ada Skema Bayar aktif. Buat skema (CASH/KPR/INHOUSE) dulu di Setup Sales → Skema Bayar.';

            return;
        }

        foreach ($rows as $idx => $row) {
            $code = trim((string) ($row['no_unit'] ?? $row['no'] ?? ''));
            if ($code === '') {
                $this->skipped++;

                continue;
            }

            $unit = UnitCodeParser::findUnit($code, $this->tenantId);
            if (! $unit) {
                $this->errors[] = 'Row '.($idx + 2).': Unit "'.$code.'" tidak ditemukan.';
                $this->skipped++;

                continue;
            }

            $matchedAny = false;
            $primaryPrice = null;

            foreach ($skemas as $skema) {
                $price = $this->priceForSkema($row, $skema);
                if ($price === null) {
                    continue; // kolom skema ini tidak ada / kosong untuk unit ini
                }
                $matchedAny = true;
                if ($primaryPrice === null && $price > 0) {
                    $primaryPrice = $price;
                }

                try {
                    $existing = UnitPricelist::query()
                        ->where('unit_id', $unit->id)
                        ->where('skema_id', $skema->id)
                        ->first();

                    $payload = [
                        'tenant_id' => $this->tenantId,
                        'unit_id' => $unit->id,
                        'skema_id' => $skema->id,
                        'no_skema' => $skema->code,
                        'price_list' => $price,
                    ];

                    if ($existing) {
                        $existing->update($payload);
                        $this->updated++;
                    } else {
                        UnitPricelist::create($payload);
                        $this->created++;
                    }
                } catch (\Throwable $e) {
                    $this->errors[] = 'Row '.($idx + 2).' ('.$code.' / '.$skema->code.'): '.$e->getMessage();
                }
            }

            if (! $matchedAny) {
                $this->errors[] = 'Row '.($idx + 2).' ('.$code.'): tidak ada kolom harga skema yang cocok. '
                    .'Pastikan ada kolom seperti "Harga Cash", "Harga KPR", "Harga Inhouse".';
                $this->skipped++;

                continue;
            }

            // Sinkron default harga_jual unit dari harga skema pertama yang terisi.
            if ($primaryPrice !== null && $primaryPrice > 0) {
                $unit->update(['harga_jual' => $primaryPrice]);
            }
        }
    }

    /**
     * Ambil harga untuk satu skema dari baris Excel. Mencocokkan beberapa
     * kemungkinan nama kolom: "harga_{code}", "harga_{name}", "{code}", "{name}",
     * dan "price_{code}". Mengembalikan null bila kolom tak ada/kosong.
     */
    private function priceForSkema(Collection $row, Skema $skema): ?float
    {
        $candidates = [];
        foreach ([$skema->code, $skema->name] as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $snake = Str::slug($token, '_');           // "KPR DP 3X" → "kpr_dp_3x"
            $candidates[] = 'harga_'.$snake;
            $candidates[] = 'price_'.$snake;
            $candidates[] = 'price_list_'.$snake;
            $candidates[] = $snake;
        }

        foreach (array_unique($candidates) as $key) {
            if (! $row->has($key)) {
                continue;
            }
            $v = $row[$key];
            if ($v === null || $v === '' || $v === '-') {
                return 0.0; // kolom ada tapi kosong → harga 0 (skema tak berlaku utk unit ini)
            }
            $n = is_numeric($v) ? (float) $v : (float) preg_replace('/[^\d.-]/', '', (string) $v);

            return $n;
        }

        return null; // kolom skema ini tidak ada di sheet
    }
}
