<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Kategori arus kas (modul Cash Flow pada Cash Book) — lihat migration 2026_08_02_100000. */
class BsCfCategory extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cf_categories';
    protected $fillable = ['tenant_id', 'section', 'group_name', 'code', 'name', 'sort_order', 'is_active'];
    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    /** Tiga seksi laporan; urutannya menentukan urutan tampil. */
    public const SECTIONS = [
        'inflow_ops'     => 'Kategori Inflow Ops',
        'inflow_non_ops' => 'Kategori Inflow Non Ops',
        'outflow'        => 'Kategori Outflow',
    ];

    /** Inflow menambah kas, outflow mengurangi — dipakai saat menghitung nett cashflow. */
    public function isInflow(): bool { return $this->section !== 'outflow'; }
}
