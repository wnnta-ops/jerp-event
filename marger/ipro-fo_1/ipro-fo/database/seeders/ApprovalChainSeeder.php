<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class ApprovalChainSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('code', 'MUP')->firstOrFail();

        // Discount approval — 3 tier sesuai config/domain.php
        $discountChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_discount', 'code' => 'DISC-MID'],
            [
                'name'             => 'Diskon Menengah (3-7%)',
                'match_conditions' => ['discount_pct' => ['min' => 3, 'max' => 7]],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => true,
        ]);

        $discountHigh = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_discount', 'code' => 'DISC-HIGH'],
            [
                'name'             => 'Diskon Tinggi (7-12%)',
                'match_conditions' => ['discount_pct' => ['min' => 7, 'max' => 12]],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountHigh->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountHigh->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'SalesDirector', 'is_final' => true,
        ]);

        $discountExt = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_discount', 'code' => 'DISC-EXT'],
            [
                'name'             => 'Diskon Ekstrim (>12%)',
                'match_conditions' => ['discount_pct' => ['min' => 12, 'max' => 100]],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountExt->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountExt->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'SalesDirector', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $discountExt->id, 'level' => 3], [
            'approver_type' => 'role', 'approver_value' => 'Direktur', 'is_final' => true,
        ]);

        // Cancellation approval
        $cancelChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_cancel', 'code' => 'CXL-DEFAULT'],
            [
                'name'             => 'Pembatalan Booking',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $cancelChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $cancelChain->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'Direktur', 'is_final' => true,
        ]);

        // Refund
        $refundChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'refund', 'code' => 'REF-DEFAULT'],
            [
                'name'             => 'Refund',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $refundChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'FinanceManager', 'is_final' => true,
        ]);

        // Ganti Nama (Name Change)
        $gnChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_gn', 'code' => 'GN-DEFAULT'],
            [
                'name'             => 'Ganti Nama Booking',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $gnChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $gnChain->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'LegalManager', 'is_final' => true,
        ]);

        // Ganti Unit (Unit Change)
        $guChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_gu', 'code' => 'GU-DEFAULT'],
            [
                'name'             => 'Ganti Unit Booking',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $guChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'SalesManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $guChain->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'SalesDirector', 'is_final' => true,
        ]);

        // Surat Peringatan (Collection #118) — butuh persetujuan finance sebelum dicetak.
        $spChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'surat_peringatan', 'code' => 'SP-DEFAULT'],
            [
                'name'             => 'Surat Peringatan Collection',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $spChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'FinanceManager', 'is_final' => true,
        ]);

        // Reschedule (Reschedule pembayaran)
        $rsChain = ApprovalChain::updateOrCreate(
            ['tenant_id' => $tenant->id, 'project_id' => null, 'document_type' => 'booking_reschedule', 'code' => 'RS-DEFAULT'],
            [
                'name'             => 'Reschedule Pembayaran',
                'match_conditions' => [],
                'is_active'        => true,
            ],
        );
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $rsChain->id, 'level' => 1], [
            'approver_type' => 'role', 'approver_value' => 'FinanceManager', 'is_final' => false,
        ]);
        ApprovalChainStep::updateOrCreate(['approval_chain_id' => $rsChain->id, 'level' => 2], [
            'approver_type' => 'role', 'approver_value' => 'Direktur', 'is_final' => true,
        ]);
    }
}
