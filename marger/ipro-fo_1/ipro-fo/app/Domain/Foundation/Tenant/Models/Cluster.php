<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cluster — CERMINAN master Lokasi di FO (Setup Unit → Lokasi), bukan master milik BO.
 *
 * Penulisnya tunggal dan ada di FO: IproMasterSync::syncLokasi(). BO hanya membaca. Karena itu
 * BO sengaja tidak punya layar tambah/ubah cluster: penulis kedua akan berkonflik dengan
 * rekonsiliasi per jam, yang akan menimpa suntingan operator tanpa pemberitahuan.
 *
 * fo_lokasi_id adalah identitasnya terhadap FO dan TIDAK pernah berubah; code & name adalah
 * label yang bebas mengikuti FO. Cluster dengan fo_lokasi_id kosong berarti bukan berasal dari
 * FO — sisa data contoh atau baris warisan sebelum jembatan ini ada.
 */
class Cluster extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'fo_lokasi_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }
}
