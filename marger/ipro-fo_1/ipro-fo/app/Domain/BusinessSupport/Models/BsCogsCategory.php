<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS Kategori Budget (+component HPP & COA default = "Set Akun Budget"). */
class BsCogsCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'component', 'default_account_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public const COMPONENTS = [
        'tanah' => 'HPP Tanah', 'bangunan' => 'HPP Bangunan', 'sarana' => 'HPP Sarana',
        'permit' => 'HPP Permit & Perencanaan', 'tools' => 'HPP Tools & Heavy Equipment',
        'kpr' => 'HPP KPR', 'bphtb' => 'HPP BPHTB', 'lain' => 'Lain-lain',
    ];

    public function defaultAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'default_account_id'); }
}
