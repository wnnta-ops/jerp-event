<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class NumeratorSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $numerators = [
            ['code' => 'CUST', 'prefix' => 'CUST', 'format' => '{PREFIX}/{YYYY}/{SEQ}',            'seq_padding' => 5, 'reset_strategy' => 'yearly'],
            ['code' => 'BOOK', 'prefix' => 'BK',   'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'KON',  'prefix' => 'KON',  'format' => '{PREFIX}/JRES/{YYYY}/{SEQ}',       'seq_padding' => 5, 'reset_strategy' => 'yearly'],
            ['code' => 'TTS',  'prefix' => 'TTS',  'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'KW',   'prefix' => 'KW',   'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'MEMO', 'prefix' => 'MEMO', 'format' => '{PREFIX}/{YY}{MM}/{SEQ}',          'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'PPJB', 'prefix' => 'PPJB', 'format' => '{PREFIX}/JRES/{YYYY}/{SEQ}',       'seq_padding' => 5, 'reset_strategy' => 'yearly'],
            ['code' => 'AJB',  'prefix' => 'AJB',  'format' => '{PREFIX}/JRES/{YYYY}/{SEQ}',       'seq_padding' => 5, 'reset_strategy' => 'yearly'],
            ['code' => 'BAST', 'prefix' => 'BAST', 'format' => '{PREFIX}/JRES/{YYYY}/{SEQ}',       'seq_padding' => 5, 'reset_strategy' => 'yearly'],
            ['code' => 'NUP',  'prefix' => 'NUP',  'format' => '{PREFIX}/JRES/{YYYY}/{SEQ}',       'seq_padding' => 4, 'reset_strategy' => 'yearly'],
            ['code' => 'KPR',  'prefix' => 'KPR',  'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'SP1',  'prefix' => 'SP1',  'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 4, 'reset_strategy' => 'monthly'],
            ['code' => 'SP2',  'prefix' => 'SP2',  'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 4, 'reset_strategy' => 'monthly'],
            ['code' => 'SP3',  'prefix' => 'SP3',  'format' => '{PREFIX}/JRES/{YY}{MM}/{SEQ}',     'seq_padding' => 4, 'reset_strategy' => 'monthly'],
            ['code' => 'PJT',  'prefix' => 'PJT',  'format' => '{PREFIX}/{YY}{MM}/{SEQ}',          'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'REF',  'prefix' => 'REF',  'format' => '{PREFIX}/{YY}{MM}/{SEQ}',          'seq_padding' => 5, 'reset_strategy' => 'monthly'],
            ['code' => 'FP',   'prefix' => 'FP',   'format' => '{PREFIX}/{YY}{MM}/{SEQ}',          'seq_padding' => 6, 'reset_strategy' => 'monthly'],
        ];

        foreach ($numerators as $n) {
            Numerator::updateOrCreate(
                ['tenant_id' => $tenant->id, 'project_id' => null, 'code' => $n['code']],
                $n,
            );
        }
    }
}
