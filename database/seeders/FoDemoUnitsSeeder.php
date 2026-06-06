<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Illuminate\Database\Seeder;

/**
 * Seeds demo unit types + 12 sample units so the public NUP form's
 * "Pilihan Unit 1/2/3" dropdowns aren't empty. Safe to re-run (idempotent).
 */
class FoDemoUnitsSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->where('code', 'JR')->first();
        if (! $project) {
            return;
        }

        $types = [
            ['code' => 'T36', 'name' => 'Tipe 36/72',  'lt' => 72,  'lb' => 36, 'harga' => 280_000_000, 'blok' => 'A'],
            ['code' => 'T45', 'name' => 'Tipe 45/90',  'lt' => 90,  'lb' => 45, 'harga' => 425_000_000, 'blok' => 'B'],
            ['code' => 'T60', 'name' => 'Tipe 60/120', 'lt' => 120, 'lb' => 60, 'harga' => 650_000_000, 'blok' => 'C'],
        ];

        foreach ($types as $t) {
            $type = UnitType::firstOrCreate(
                ['tenant_id' => 1, 'project_id' => $project->id, 'code' => $t['code']],
                [
                    'name' => $t['name'],
                    'luas_tanah' => $t['lt'],
                    'luas_bangunan' => $t['lb'],
                    'jumlah_kamar' => 2,
                    'jumlah_kmandi' => 1,
                    'harga_dasar' => $t['harga'],
                    'is_active' => true,
                ]
            );

            // 5 units per blok (per tipe)
            foreach (range(1, 5) as $i) {
                Unit::firstOrCreate(
                    [
                        'tenant_id' => 1,
                        'project_id' => $project->id,
                        'blok' => $t['blok'],
                        'no_unit' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    ],
                    [
                        'unit_type_id' => $type->id,
                        'luas_tanah' => $t['lt'],
                        'luas_bangunan' => $t['lb'],
                        'harga_jual' => $t['harga'] + ($i * 5_000_000),
                        'status' => 'TERSEDIA',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
