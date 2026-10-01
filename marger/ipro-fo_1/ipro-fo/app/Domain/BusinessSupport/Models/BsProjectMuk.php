<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu "proyek" MUK yang ditempelkan ke sebuah Project kita.
 *
 * Sebuah Project punya BANYAK baris di sini; sebuah proyek MUK hanya boleh punya satu
 * (dijaga indeks unik). Alasan keduanya ada di migrasi 2026_10_01_100000.
 */
class BsProjectMuk extends Model
{
    use HasTenant;

    protected $table = 'bs_project_muk';

    protected $fillable = ['tenant_id', 'project_id', 'muk_proyek_kode', 'muk_proyek_nama', 'created_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(BsProject::class, 'project_id');
    }
}
