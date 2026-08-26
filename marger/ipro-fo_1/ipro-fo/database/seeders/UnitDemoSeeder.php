<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Models\UnitType;
use Illuminate\Database\Seeder;

class UnitDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::where('code', 'JRES')->firstOrFail();

        $type70 = UnitType::updateOrCreate(
            ['project_id' => $project->id, 'code' => 'T70'],
            ['tenant_id' => $tenant->id, 'name' => 'Tipe 70/120', 'lt' => 120, 'lb' => 70, 'bedrooms' => 3, 'bathrooms' => 2],
        );
        $type50 = UnitType::updateOrCreate(
            ['project_id' => $project->id, 'code' => 'T50'],
            ['tenant_id' => $tenant->id, 'name' => 'Tipe 50/90', 'lt' => 90, 'lb' => 50, 'bedrooms' => 2, 'bathrooms' => 1],
        );

        $clusters = Cluster::where('project_id', $project->id)->orderBy('code')->get();

        // Sejak cluster tidak lagi di-seed (ia cerminan master Lokasi FO), seeder ini biasanya
        // tidak menghasilkan apa pun. Dikatakan apa adanya — loop kosong yang diam membuat
        // "kenapa tidak ada unit demo?" jadi teka-teki yang harus dilacak ke seeder lain.
        if ($clusters->isEmpty()) {
            $this->command?->warn(
                'UnitDemoSeeder dilewati: belum ada cluster. '
                .'Cluster berasal dari FO — jalankan `php artisan ipro:backfill lokasi` di aplikasi FO.'
            );

            return;
        }

        $statusPool = ['available', 'available', 'available', 'hold', 'booked'];
        $counter = 0;

        foreach ($clusters as $clusterIndex => $cluster) {
            foreach (range(1, 6) as $i) {
                $code = "{$cluster->code}-".str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $type = $i <= 3 ? $type70 : $type50;
                $basePrice = $type === $type70 ? 850_000_000 : 650_000_000;

                Unit::updateOrCreate(
                    ['project_id' => $project->id, 'unit_code' => $code],
                    [
                        'tenant_id'     => $tenant->id,
                        'cluster_id'    => $cluster->id,
                        'unit_type_id'  => $type->id,
                        'base_price'    => $basePrice,
                        'current_price' => $basePrice,
                        'status'        => $statusPool[$counter % count($statusPool)],
                        'attributes'    => ['facing' => $clusterIndex % 2 === 0 ? 'utara' : 'selatan'],
                    ],
                );
                $counter++;
            }
        }
    }
}
