<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Domain\Sales\Nup\Models\NupScheme;
use Illuminate\Database\Seeder;

class NupSchemeSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::where('code', 'JRES')->first();
        if (! $project) return;

        NupScheme::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'code' => 'NUP-JRES-2026'],
            [
                'name'              => 'NUP Java Residence 2026',
                'nup_amount'        => 5_000_000,
                'open_from'         => '2026-01-01',
                'open_to'           => '2026-12-31',
                'is_refundable'     => true,
                'refund_penalty_pct'=> 10,
                'is_active'         => true,
            ],
        );
    }
}
