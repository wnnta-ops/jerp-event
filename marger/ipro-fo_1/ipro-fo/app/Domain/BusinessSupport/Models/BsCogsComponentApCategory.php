<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tautan Komponen COGS → Kategori Invoice (Account Payable).
 *
 * Dipakai komponen yang bersumber dari invoice hutang (Tanah, License Permit). Kolom
 * component memakai kunci BsCogsCategory::COMPONENTS ('tanah', 'permit'). Satu kategori
 * invoice hanya boleh tertaut ke SATU komponen (unique tenant_id + ap_category_id).
 */
class BsCogsComponentApCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_component_ap_categories';

    protected $fillable = ['tenant_id', 'component', 'ap_category_id'];

    public function apCategory(): BelongsTo
    {
        return $this->belongsTo(BsApCategory::class, 'ap_category_id');
    }
}
