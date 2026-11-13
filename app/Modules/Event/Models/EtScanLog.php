<?php

namespace App\Modules\Event\Models;

use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log setiap percobaan scan. Append-only, jadi sengaja TIDAK memakai
 * TenantAwareModel (yang membawa SoftDeletes + activity log per baris) — tetapi
 * tetap ber-TenantScope dan mengisi tenant_id dengan cara yang sama.
 */
class EtScanLog extends Model
{
    protected $table = 'et_scan_logs';

    public const UPDATED_AT = null;

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const DUPLICATE = 'duplicate';

    public const UNDONE = 'undone';

    protected $fillable = [
        'tenant_id', 'event_id', 'scan_point_id', 'user_id', 'raw_payload',
        'fo_nup_registration_id', 'result', 'reason', 'server_time', 'user_agent', 'effect',
    ];

    protected $casts = [
        'server_time' => 'datetime',
        'effect' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });

        // Append-only: baris log tak boleh diubah/dihapus lewat model.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @return BelongsTo<EtEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EtEvent::class, 'event_id');
    }

    /** @return BelongsTo<EtScanPoint, $this> */
    public function scanPoint(): BelongsTo
    {
        return $this->belongsTo(EtScanPoint::class, 'scan_point_id');
    }
}
