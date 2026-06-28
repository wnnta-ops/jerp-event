<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AgentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'POKOK',  'label' => 'Agen Pokok'],
            ['code' => 'P',      'label' => 'Personal'],
            ['code' => 'R',      'label' => 'Referral'],
            ['code' => 'CF',     'label' => 'Customer Finder'],
            ['code' => 'CFP',    'label' => 'CF Personal'],
            ['code' => 'CFR',    'label' => 'CF Referral'],
            ['code' => 'REWARD', 'label' => 'Reward Program'],
        ];

        foreach ($types as $t) {
            DB::table('agent_types')->updateOrInsert(
                ['code' => $t['code']],
                array_merge($t, ['created_at' => now(), 'updated_at' => now()]),
            );
        }
    }
}
