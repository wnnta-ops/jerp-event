<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master Project (Contractor Management → Setup → Project).
 */
class BsProject extends Model
{
    use HasTenant;

    protected $table = 'bs_projects';
    protected $fillable = ['tenant_id', 'code', 'name', 'dept', 'cluster', 'tahap', 'unit', 'coa_account_id', 'is_active', 'notes', 'created_by'];
    protected $casts = ['is_active' => 'boolean', 'coa_account_id' => 'integer'];

    /** COA default project — di-prefill sebagai Akun Konstruksi/CIP saat Register SPK. */
    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(BsAccount::class, 'coa_account_id');
    }
}
