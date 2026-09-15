<?php

namespace App\Models\Bo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost Center milik aplikasi BO / Java Core System — tabel `bs_cost_centers` di
 * database `ipro_bo`. Inilah cost center yang SEBENARNYA dipakai: yang mengikat
 * jurnal, budget, dan bon sementara.
 *
 * Jangan tertukar dengan App\Models\CostCenter (tabel `cost_centers` di DB jerp) —
 * itu milik modul BO lama yang sudah dorman (bo_jurnal_detil & bo_cb_vouchers
 * 0 baris) dan menu-nya sudah dicabut dari panel System supaya tidak ada yang
 * mengisi di layar yang salah.
 *
 * Hanya dibaca dari sini; perawatan isinya tetap di aplikasi BO (Setup → Cost Center).
 */
class BoCostCenter extends Model
{
    use SoftDeletes;

    protected $connection = 'ipro_bo';

    protected $table = 'bs_cost_centers';

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'manager', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** "CC-FIN — Finance & Accounting" untuk dropdown. */
    public function getLabelAttribute(): string
    {
        return trim(($this->code ? $this->code.' — ' : '').$this->name);
    }
}
