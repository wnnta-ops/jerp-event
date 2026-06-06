<?php

namespace App\Modules\BO\GeneralLedger\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_chart_of_accounts';

    protected $fillable = [
        'tenant_id',
        'kode',
        'nama',
        'tipe_akun',
        'saldo_normal',
        'level',
        'parent_kode',
        'is_pajak',
        'is_header',
        'is_active',
        'keterangan',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_pajak' => 'boolean',
        'is_header' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Default normal balance for an account type. */
    public static function normalBalanceFor(string $tipeAkun): string
    {
        return in_array($tipeAkun, ['ASSET', 'EXPENSE'], true) ? 'DEBET' : 'KREDIT';
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_kode', 'kode');
    }

    public function jurnalDetils(): HasMany
    {
        return $this->hasMany(JurnalDetil::class, 'kode_account', 'kode');
    }

    public function scopePostable($query)
    {
        return $query->where('is_header', false)->where('is_active', true);
    }
}
