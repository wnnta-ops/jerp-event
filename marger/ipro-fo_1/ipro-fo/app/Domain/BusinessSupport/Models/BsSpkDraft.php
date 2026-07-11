<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Draft/template default dokumen Cetak SPK (Set Up SPK). Satu baris per tenant.
 */
class BsSpkDraft extends Model
{
    use HasTenant;

    protected $table = 'bs_spk_drafts';

    protected $fillable = [
        'tenant_id', 'judul', 'subjudul', 'tempat', 'pembukaan', 'pasals', 'updated_by',
    ];

    protected $casts = [
        'pasals' => 'array',
    ];
}
