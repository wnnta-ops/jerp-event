<?php

namespace App\Models\Concerns;

use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

abstract class TenantAwareModel extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    /**
     * Audit trail (Spatie Activitylog) — aktif untuk SEMUA model turunan
     * (master & transaksi). Mencatat create/update/delete pada kolom fillable,
     * hanya yang berubah, tanpa menyimpan log kosong. Tampil di panel System →
     * Audit Log. Kolom teknis (tenant_id, audit, timestamp) dikecualikan agar
     * log tidak penuh noise.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept(['tenant_id', 'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at'])
            ->useLogName('data')
            ->setDescriptionForEvent(fn (string $event): string => class_basename($this).' '.match ($event) {
                'created' => 'dibuat',
                'updated' => 'diubah',
                'deleted' => 'dihapus',
                'restored' => 'dipulihkan',
                default => $event,
            });
    }
}
