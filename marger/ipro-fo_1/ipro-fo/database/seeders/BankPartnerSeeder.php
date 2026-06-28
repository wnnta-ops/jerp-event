<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\BankLoan\Models\BankProduct;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class BankPartnerSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        $banks = [
            ['code' => 'BCA',   'name' => 'Bank Central Asia'],
            ['code' => 'MDR',   'name' => 'Bank Mandiri'],
            ['code' => 'BRI',   'name' => 'Bank Rakyat Indonesia'],
            ['code' => 'BNI',   'name' => 'Bank Negara Indonesia'],
            ['code' => 'BTN',   'name' => 'Bank Tabungan Negara'],
        ];

        foreach ($banks as $b) {
            $bank = BankPartner::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $b['code']],
                ['name' => $b['name'], 'is_active' => true],
            );

            BankProduct::updateOrCreate(
                ['bank_partner_id' => $bank->id, 'code' => $b['code'].'-KPR-15'],
                ['name' => $b['name'].' KPR 15 Tahun', 'interest_pct' => 8.5, 'max_tenor_month' => 180, 'is_active' => true],
            );
            BankProduct::updateOrCreate(
                ['bank_partner_id' => $bank->id, 'code' => $b['code'].'-KPR-20'],
                ['name' => $b['name'].' KPR 20 Tahun', 'interest_pct' => 9.0, 'max_tenor_month' => 240, 'is_active' => true],
            );
        }
    }
}
