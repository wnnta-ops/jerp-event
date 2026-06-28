<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kategori/baris laporan arus kas — per metode (direct/indirect) & aktivitas.
 */
class BsCashFlowCategory extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cash_flow_categories';

    protected $fillable = ['tenant_id', 'kind', 'code', 'name', 'activity', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public const KINDS = ['direct' => 'Direct', 'indirect' => 'Indirect'];

    public const ACTIVITIES = ['operating' => 'Operasi', 'investing' => 'Investasi', 'financing' => 'Pendanaan'];
}
