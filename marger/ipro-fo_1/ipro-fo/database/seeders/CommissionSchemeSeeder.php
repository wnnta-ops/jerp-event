<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Commission\Models\CommissionScheme;
use App\Domain\Commission\Models\CommissionSchemeTerm;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class CommissionSchemeSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $scheme = CommissionScheme::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'KOM-STD'],
            ['name' => 'Komisi Standar 2026', 'is_active' => true],
        );

        $terms = [
            ['agent_type' => 'POKOK', 'trigger_event' => 'booking',    'rate_pct' => 1.5],
            ['agent_type' => 'POKOK', 'trigger_event' => 'contract',   'rate_pct' => 1.0],
            ['agent_type' => 'POKOK', 'trigger_event' => 'payoff',     'rate_pct' => 0.5],
            ['agent_type' => 'P',     'trigger_event' => 'booking',    'rate_pct' => 0.5],
            ['agent_type' => 'R',     'trigger_event' => 'booking',    'rate_pct' => 0.3],
            ['agent_type' => 'CF',    'trigger_event' => 'booking',    'rate_pct' => 0.5],
        ];

        foreach ($terms as $i => $t) {
            CommissionSchemeTerm::updateOrCreate(
                ['commission_scheme_id' => $scheme->id, 'agent_type' => $t['agent_type'], 'trigger_event' => $t['trigger_event']],
                $t + ['term_no' => $i + 1],
            );
        }
    }
}
