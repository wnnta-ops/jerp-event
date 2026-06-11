<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Numerator;
use App\Models\Project;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(
            ['code' => 'MUP'],
            [
                'tenant_id' => 1,
                'name' => 'PT Mitra Utama Properti',
                'npwp' => '01.234.567.8-901.000',
                'address' => 'Jakarta',
                'is_active' => true,
            ]
        );

        Project::firstOrCreate(
            ['code' => 'JR'],
            [
                'tenant_id' => 1,
                'company_id' => $company->id,
                'name' => 'Java Residence',
                'address' => 'Bekasi, Jawa Barat',
                'is_active' => true,
            ]
        );

        $banks = [
            ['code' => 'BCA',     'name' => 'Bank Central Asia',          'swift_code' => 'CENAIDJA'],
            ['code' => 'BNI',     'name' => 'Bank Negara Indonesia',      'swift_code' => 'BNINIDJA'],
            ['code' => 'BRI',     'name' => 'Bank Rakyat Indonesia',      'swift_code' => 'BRINIDJA'],
            ['code' => 'MANDIRI', 'name' => 'Bank Mandiri',               'swift_code' => 'BMRIIDJA'],
            ['code' => 'BTN',     'name' => 'Bank Tabungan Negara',       'swift_code' => 'BTANIDJA'],
            ['code' => 'CIMB',    'name' => 'CIMB Niaga',                 'swift_code' => 'BNIAIDJA'],
        ];
        foreach ($banks as $b) {
            Bank::firstOrCreate(
                ['code' => $b['code']],
                $b + ['tenant_id' => 1, 'is_active' => true]
            );
        }

        $costCenters = [
            ['code' => 'MKT', 'name' => 'Marketing'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'LEG', 'name' => 'Legal'],
            ['code' => 'OPR', 'name' => 'Operasional'],
            ['code' => 'HRD', 'name' => 'HR & GA'],
            ['code' => 'IT',  'name' => 'IT'],
        ];
        foreach ($costCenters as $cc) {
            CostCenter::firstOrCreate(
                ['tenant_id' => 1, 'code' => $cc['code']],
                $cc + ['tenant_id' => 1, 'company_id' => $company->id, 'is_active' => true]
            );
        }

        $numerators = [
            ['code' => 'CUST',    'format' => 'CUST-{YYYY}-{seq:5}'],
            ['code' => 'RSV',     'format' => 'RSV-{YYYY}{MM}-{seq:5}'],
            ['code' => 'KONTRAK', 'format' => 'KTR-{YYYY}-{seq:5}'],
            ['code' => 'KPR', 'format' => 'KPR-{YYYY}-{seq:5}'],
            ['code' => 'TTS', 'format' => 'TTS-{YYYY}{MM}-{seq:5}'],
            ['code' => 'BKM', 'format' => 'BKM-{YYYY}{MM}-{seq:5}'],
            ['code' => 'BKK', 'format' => 'BKK-{YYYY}{MM}-{seq:5}'],
            ['code' => 'BGM', 'format' => 'BGM-{YYYY}{MM}-{seq:5}'],
            ['code' => 'MEMO', 'format' => 'MEMO-{YYYY}{MM}-{seq:5}'],
            ['code' => 'REFUND', 'format' => 'RF-{YYYY}{MM}-{seq:5}'],
            ['code' => 'TA', 'format' => 'TA-{YYYY}{MM}-{seq:5}'],
            ['code' => 'PH', 'format' => 'PH-{YYYY}{MM}-{seq:5}'],
            ['code' => 'PU', 'format' => 'PU-{YYYY}{MM}-{seq:5}'],
            ['code' => 'KOMISI',  'format' => 'KOM-{YYYY}{MM}-{seq:5}'],
            ['code' => 'REWARD',  'format' => 'RWD-{YYYY}-{seq:5}'],
            ['code' => 'TIPETARIF',    'format' => 'TT-{seq:4}'],
            ['code' => 'SKEMAKOMISI',  'format' => 'SK-{seq:4}'],
            ['code' => 'SKEMABLT',     'format' => 'SB-{seq:4}'],
            ['code' => 'TERMINBLT',    'format' => 'TB-{seq:4}'],
            ['code' => 'TERMINKOMISI', 'format' => 'TK-{seq:4}'],
            ['code' => 'SKEMAREWARD',  'format' => 'SR-{seq:4}'],
            ['code' => 'TIPEGIMMICK', 'format' => 'TG-{seq:4}'],
            ['code' => 'GIMMICK',     'format' => 'GMK-{seq:4}'],
            ['code' => 'APPROVAL', 'format' => 'APR-{YYYY}{MM}-{seq:5}'],
            ['code' => 'PPJB', 'format' => 'PPJB-{YYYY}-{seq:5}'],
            ['code' => 'AJB', 'format' => 'AJB-{YYYY}-{seq:5}'],
            ['code' => 'RA',  'format' => 'RA-{YYYY}-{seq:5}'],
            ['code' => 'PR',  'format' => 'PR-{YYYY}-{seq:5}'],
            ['code' => 'RR',  'format' => 'RR-{YYYY}-{seq:5}'],
            ['code' => 'NUP', 'format' => 'NUP-{seq:5}'],         // diterbitkan saat approval (FIFO global)
        ];
        foreach ($numerators as $n) {
            Numerator::firstOrCreate(
                ['tenant_id' => 1, 'company_id' => null, 'code' => $n['code'], 'period' => date('Y')],
                $n + ['tenant_id' => 1, 'period' => date('Y'), 'last_seq' => 0, 'is_active' => true]
            );
        }
    }
}
