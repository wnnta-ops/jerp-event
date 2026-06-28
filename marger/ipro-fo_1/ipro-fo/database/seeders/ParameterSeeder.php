<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParameterSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $params = [
            ['group' => 'tax',      'key' => 'ppn_pct',                'value' => '11',          'data_type' => 'decimal', 'label' => 'PPN (%)'],
            ['group' => 'currency', 'key' => 'default',                'value' => 'IDR',         'data_type' => 'string',  'label' => 'Mata Uang Default'],
            ['group' => 'denda',    'key' => 'rate_per_day',           'value' => '0.0005',      'data_type' => 'decimal', 'label' => 'Denda per Hari'],
            ['group' => 'denda',    'key' => 'grace_days',             'value' => '0',           'data_type' => 'int',     'label' => 'Hari Toleransi Denda'],
            ['group' => 'hold',     'key' => 'default_days',           'value' => '7',           'data_type' => 'int',     'label' => 'Lama Hold Unit (hari)'],
            ['group' => 'hold',     'key' => 'max_days',               'value' => '14',          'data_type' => 'int',     'label' => 'Maksimal Hold Unit (hari)'],
            ['group' => 'nup',      'key' => 'refund_penalty_pct',     'value' => '10',          'data_type' => 'decimal', 'label' => 'Penalti Refund NUP (%)'],
            ['group' => 'company',  'key' => 'name',                   'value' => 'PT MUP',      'data_type' => 'string',  'label' => 'Nama Perusahaan',     'is_public' => true],
            ['group' => 'company',  'key' => 'address',                'value' => 'Bandung',     'data_type' => 'string',  'label' => 'Alamat Perusahaan',   'is_public' => true],
        ];

        foreach ($params as $p) {
            DB::table('parameters')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'group' => $p['group'], 'key' => $p['key']],
                array_merge($p, [
                    'tenant_id'  => $tenant->id,
                    'is_public'  => $p['is_public'] ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }
    }
}
