<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Company;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $company = Company::where('code', 'MUP')->firstOrFail();

        $project = Project::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'JRES'],
            [
                'company_id'    => $company->id,
                'name'          => 'Java Residence',
                'location'      => 'Bandung, Jawa Barat',
                'launched_at'   => '2024-01-01',
                'siteplan_meta' => ['canvas_w' => 1200, 'canvas_h' => 800],
                'is_active'     => true,
            ],
        );

        foreach (['A', 'B', 'C'] as $code) {
            Cluster::updateOrCreate(
                ['project_id' => $project->id, 'code' => "C-{$code}"],
                ['tenant_id' => $tenant->id, 'name' => "Cluster {$code}"],
            );
        }
    }
}
