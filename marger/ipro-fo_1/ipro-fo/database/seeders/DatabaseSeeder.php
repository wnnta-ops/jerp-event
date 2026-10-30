<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            CompanySeeder::class,
            ProjectSeeder::class,
            PermissionSeeder::class,
            GranularPermissionSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            NumeratorSeeder::class,
            ParameterSeeder::class,
            AgentTypeSeeder::class,
            PaymentSchemeSeeder::class,
            UnitDemoSeeder::class,
            ApprovalChainSeeder::class,
            ApprovalInboxSeeder::class,
            ApBerkasApprovalSeeder::class,
            BankPartnerSeeder::class,
            KprSetupSeeder::class,
            BusinessSupportSeeder::class,
            NupSchemeSeeder::class,
            CommissionSchemeSeeder::class,
            NotificationTemplateSeeder::class,
        ]);
    }
}
