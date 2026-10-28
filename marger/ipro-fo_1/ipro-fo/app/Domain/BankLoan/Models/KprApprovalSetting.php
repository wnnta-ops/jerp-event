<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Setup Approval KPR — satu baris per tahap (lihat KprPerubahanService::STAGES).
 */
class KprApprovalSetting extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'stage', 'is_required', 'approver_user_ids', 'bypass_user_ids'];

    protected $casts = [
        'is_required'       => 'boolean',
        'approver_user_ids' => 'array',
        'bypass_user_ids'   => 'array',
    ];
}
