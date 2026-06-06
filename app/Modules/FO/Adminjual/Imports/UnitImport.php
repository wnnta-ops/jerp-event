<?php

namespace App\Modules\FO\Adminjual\Imports;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import unit master dari Excel (sheet UNIT).
 * Kolom yang diharapkan (heading row 1, case-insensitive snake-equivalent):
 *   Tahap, Blok, Nomor, Alamat, Price List Minimum, Price List Rumah,
 *   Price List Kavling, Zoning, Panjang, Lebar, Tinggi, Luas Tanah,
 *   Luas Lebih Tanah, Luas Bangunan, Lebar Jalan, Arah Hadap, Panorama,
 *   Tipe Properti, Kategori Unit
 *
 * Unit di-upsert berdasarkan (project_id, tahap, blok, no_unit).
 * Project ditentukan oleh argumen konstruktor (operator pilih project di UI).
 */
class UnitImport implements ToCollection, WithHeadingRow
{
    use Importable;

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int,string> */
    public array $errors = [];

    public function __construct(
        private readonly int $projectId,
        private readonly int $tenantId = 1,
    ) {}

    public function headingRow(): int
    {
        return 1;
    }

    public function collection(Collection $rows): void
    {
        $project = Project::find($this->projectId);
        if (! $project) {
            $this->errors[] = 'Project ID '.$this->projectId.' tidak ditemukan.';

            return;
        }

        foreach ($rows as $idx => $row) {
            $lokasiVal = $this->str($row, ['lokasi', 'kode_lokasi', 'lokasi_kode']);
            $tahapVal = $this->str($row, ['tahap', 'kode_tahap']);
            $blok = $this->str($row, ['blok']);
            $no = $this->str($row, ['nomor', 'no_unit', 'no']);

            if (! $lokasiVal || ! $tahapVal || ! $blok || ! $no) {
                $this->errors[] = 'Row '.($idx + 2).': Lokasi, Tahap, Blok, dan Nomer wajib diisi.';
                $this->skipped++;

                continue;
            }

            // Lokasi & Tahap WAJIB sudah terdaftar di master (Setup Unit). Tolak bila tidak ada.
            $lokasi = Lokasi::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('project_id', $this->projectId)
                ->where(fn ($q) => $q->where('kode', $lokasiVal)->orWhere('nama', $lokasiVal))
                ->first();
            if (! $lokasi) {
                $this->errors[] = 'Row '.($idx + 2).': Lokasi "'.$lokasiVal.'" belum terdaftar di Setup Unit → Lokasi.';
                $this->skipped++;

                continue;
            }

            $tahap = Tahap::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('lokasi_id', $lokasi->id)
                ->where(fn ($q) => $q->where('kode', $tahapVal)->orWhere('nama', $tahapVal))
                ->first();
            if (! $tahap) {
                $this->errors[] = 'Row '.($idx + 2).': Tahap "'.$tahapVal.'" belum terdaftar untuk Lokasi "'.$lokasi->kode.'".';
                $this->skipped++;

                continue;
            }

            // Kolom "Tipe" (mis. CAMAR 6 X 15) → normalisasi ke master fo_unit_types
            // (find-or-create per project) lalu tautkan unit_type_id. Sekali upload,
            // master tipe + relasi unit langsung terisi (popup siteplan menampilkannya).
            $tipeVal = $this->str($row, ['tipe', 'tipe_unit', 'type', 'tipe_rumah']);
            $unitTypeId = $tipeVal !== null
                ? $this->resolveUnitType($tipeVal, $this->num($row, ['luas_tanah']), $this->num($row, ['luas_bangunan']))
                : null;

            $payload = [
                'tenant_id' => $this->tenantId,
                'project_id' => $this->projectId,
                'lokasi_id' => $lokasi->id,
                'tahap_id' => $tahap->id,
                'blok' => $blok,
                'no_unit' => $no,
                'alamat' => $this->str($row, ['alamat']),
                'price_list_minimum' => $this->num($row, ['price_list_minimum']),
                'harga_jual' => $this->num($row, ['price_list_rumah', 'harga_jual', 'harga']),
                'price_list_kavling' => $this->num($row, ['price_list_kavling']),
                'zoning' => $this->str($row, ['zoning']),
                'panjang' => $this->num($row, ['panjang']),
                'lebar' => $this->num($row, ['lebar']),
                'tinggi' => $this->num($row, ['tinggi']),
                'luas_tanah' => $this->num($row, ['luas_tanah']),
                'luas_lebih_tanah' => $this->num($row, ['luas_lebih_tanah']),
                'luas_bangunan' => $this->num($row, ['luas_bangunan']),
                'lebar_jalan' => $this->num($row, ['lebar_jalan']),
                'arah_hadap' => $this->str($row, ['arah_hadap']),
                'panorama' => $this->str($row, ['panorama']),
                'tipe_properti' => $this->str($row, ['tipe_properti']),
                'kategori_unit' => $this->str($row, ['kategori_unit']),
                'is_active' => true,
            ];

            // Hanya set saat ada nilai → baris tanpa kolom Tipe tidak menghapus relasi existing.
            if ($unitTypeId !== null) {
                $payload['unit_type_id'] = $unitTypeId;
            }

            try {
                $unit = Unit::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)
                    ->where('lokasi_id', $lokasi->id)
                    ->where('tahap_id', $tahap->id)
                    ->where('blok', $blok)
                    ->where('no_unit', $no)
                    ->first();

                if ($unit) {
                    $unit->update($payload);
                    $this->updated++;
                } else {
                    $payload['status'] = 'TERSEDIA';
                    Unit::create($payload);
                    $this->created++;
                }
            } catch (\Throwable $e) {
                $this->errors[] = 'Row '.($idx + 2).' ('.$blok.'/'.$no.'): '.$e->getMessage();
            }
        }
    }

    /**
     * Find-or-create a unit type (fo_unit_types) for this project from the
     * Excel "Tipe" value, returning its id. Dedup key = (tenant, project, code)
     * where code is a normalised slug of the type name.
     */
    private function resolveUnitType(string $tipe, ?float $luasTanah, ?float $luasBangunan): int
    {
        $code = trim(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $tipe)), '-');
        $code = $code !== '' ? Str::limit($code, 32, '') : 'TIPE';

        $ut = UnitType::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenantId, 'project_id' => $this->projectId, 'code' => $code],
            ['name' => $tipe, 'luas_tanah' => $luasTanah, 'luas_bangunan' => $luasBangunan, 'is_active' => true],
        );

        return $ut->id;
    }

    private function str(Collection $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (! $row->has($k)) {
                continue;
            }
            $v = trim((string) ($row[$k] ?? ''));
            if ($v === '' || $v === '-') {
                continue;
            }

            return $v;
        }

        return null;
    }

    private function num(Collection $row, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (! $row->has($k)) {
                continue;
            }
            $v = $row[$k] ?? null;
            if ($v === null || $v === '' || $v === '-') {
                continue;
            }
            $n = is_numeric($v) ? (float) $v : (float) preg_replace('/[^\d.-]/', '', (string) $v);
            if ($n === 0.0 && (string) $v !== '0') {
                continue;
            }

            return $n;
        }

        return null;
    }
}
