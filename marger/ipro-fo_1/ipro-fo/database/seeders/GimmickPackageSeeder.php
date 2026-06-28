<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Domain\Sales\Pricing\Enums\GimmickType;
use App\Domain\Sales\Pricing\Models\Gimmick;
use App\Domain\Sales\Pricing\Models\GimmickPackage;
use Illuminate\Database\Seeder;

class GimmickPackageSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::where('code', 'JRES')->first();

        // Master gimmick items (sesuai live observation CLUSTER 3.4)
        $items = [
            ['code' => '81', 'name' => 'FREE BPHTB',                       'gimmick_type' => GimmickType::ServiceWaiver, 'satuan_barang' => 'VOUCHER', 'harga_satuan' => 25_000_000],
            ['code' => '82', 'name' => 'FREE IPL 2 TAHUN',                 'gimmick_type' => GimmickType::Service,        'satuan_barang' => 'TAHUN',   'harga_satuan' => 5_000_000],
            ['code' => '83', 'name' => 'FREE BIAYA KPR INC ASURANSI',      'gimmick_type' => GimmickType::ServiceWaiver, 'satuan_barang' => 'VOUCHER', 'harga_satuan' => 8_000_000],
            ['code' => '84', 'name' => 'FREE TAMAN DEPAN',                 'gimmick_type' => GimmickType::Physical,       'satuan_barang' => 'M2',      'harga_satuan' => 3_000_000],
            ['code' => '85', 'name' => 'FREE AJB BBN',                     'gimmick_type' => GimmickType::ServiceWaiver, 'satuan_barang' => 'VOUCHER', 'harga_satuan' => 12_000_000],
            ['code' => '86', 'name' => 'FREE CANOPY',                      'gimmick_type' => GimmickType::Physical,       'satuan_barang' => 'BUAH',    'harga_satuan' => 4_500_000],
            ['code' => '87', 'name' => 'FREE JAMINAN ANTI RAYAP (S/D 3 TAHUN)','gimmick_type' => GimmickType::Warranty,   'satuan_barang' => 'TAHUN',   'harga_satuan' => 2_000_000],
        ];

        $createdItems = collect();
        foreach ($items as $i) {
            $g = Gimmick::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $i['code']],
                array_merge($i, [
                    'tenant_id'  => $tenant->id,
                    'project_id' => $project?->id,
                    'value'      => $i['harga_satuan'],
                    'is_active'  => true,
                ]),
            );
            $createdItems->push($g);
        }

        // Master package CLUSTER 3.4
        $package = GimmickPackage::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PKG-CLUSTER-3.4'],
            [
                'tenant_id'    => $tenant->id,
                'project_id'   => $project?->id,
                'cluster_code' => 'CLUSTER 3.4',
                'name'         => 'Paket Promo CLUSTER 3.4',
                'description'  => 'Bundle promosi untuk pembelian unit di Cluster 3.4 — FREE BPHTB, IPL 2 tahun, KPR fee, taman, AJB BBN, canopy, jaminan anti rayap',
                'is_active'    => true,
            ],
        );

        // Attach items to package
        $package->items()->sync(
            $createdItems->mapWithKeys(fn ($item) => [
                $item->id => ['qty_default' => 1, 'target_lunas_days' => 365],
            ])->toArray(),
        );

        $this->command->info("Seeded GimmickPackage '{$package->code}' with {$createdItems->count()} items");
    }
}
