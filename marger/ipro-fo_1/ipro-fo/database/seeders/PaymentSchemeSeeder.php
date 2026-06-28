<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentSchemeSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::where('code', 'JRES')->firstOrFail();

        $schemes = [
            [
                'code'              => 'CASH-KERAS',
                'name'              => 'Cash Keras (Diskon 10%)',
                'type'              => 'cash_keras',
                'down_payment_pct'  => 100,
                'installment_months'=> 0,
                'discount_pct'      => 10,
                'terms' => [
                    ['term_no' => 1, 'label' => 'Pelunasan Cash', 'basis' => 'percent', 'value' => 100, 'due_after_days' => 30, 'is_dp' => true],
                ],
            ],
            [
                'code'              => 'CICILAN-24',
                'name'              => 'Cicilan 24 Bulan',
                'type'              => 'cicilan',
                'down_payment_pct'  => 20,
                'installment_months'=> 24,
                'discount_pct'      => 0,
                'terms' => [
                    ['term_no' => 1, 'label' => 'DP',         'basis' => 'percent', 'value' => 20, 'due_after_days' => 7,  'is_dp' => true],
                    ['term_no' => 2, 'label' => 'Cicilan 24', 'basis' => 'percent', 'value' => 80, 'due_after_days' => 30, 'is_dp' => false],
                ],
            ],
            [
                'code'              => 'KPR-70',
                'name'              => 'KPR 70% / DP 30%',
                'type'              => 'kpr',
                'down_payment_pct'  => 30,
                'installment_months'=> 0,
                'discount_pct'      => 0,
                'terms' => [
                    ['term_no' => 1, 'label' => 'DP 1',           'basis' => 'percent', 'value' => 10, 'due_after_days' => 7,   'is_dp' => true],
                    ['term_no' => 2, 'label' => 'DP 2',           'basis' => 'percent', 'value' => 10, 'due_after_days' => 30,  'is_dp' => true],
                    ['term_no' => 3, 'label' => 'DP 3',           'basis' => 'percent', 'value' => 10, 'due_after_days' => 60,  'is_dp' => true],
                    ['term_no' => 4, 'label' => 'KPR Pencairan',  'basis' => 'percent', 'value' => 70, 'due_after_days' => 90,  'is_dp' => false],
                ],
            ],
        ];

        foreach ($schemes as $s) {
            $schemeId = DB::table('payment_schemes')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'project_id' => $project->id, 'code' => $s['code']],
                [
                    'name'               => $s['name'],
                    'type'               => $s['type'],
                    'down_payment_pct'   => $s['down_payment_pct'],
                    'installment_months' => $s['installment_months'],
                    'discount_pct'       => $s['discount_pct'],
                    'is_active'          => true,
                    'updated_at'         => now(),
                ],
            );

            $existing = DB::table('payment_schemes')
                ->where('tenant_id', $tenant->id)
                ->where('project_id', $project->id)
                ->where('code', $s['code'])
                ->first();

            if (! $existing) {
                continue;
            }

            DB::table('payment_scheme_terms')->where('payment_scheme_id', $existing->id)->delete();
            foreach ($s['terms'] as $term) {
                DB::table('payment_scheme_terms')->insert(array_merge($term, [
                    'payment_scheme_id' => $existing->id,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]));
            }
        }
    }
}
