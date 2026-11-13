<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Satu event (mis. Pilih Unit launching) yang terhubung ke satu event NUP FO.
 */
class EtEvent extends TenantAwareModel
{
    protected $table = 'et_events';

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'name', 'event_date', 'venue', 'fo_nup_event_id', 'status',
        'opened_at', 'closed_at', 'last_synced_at', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'fo_nup_event_id' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return HasMany<EtStation, $this> */
    public function stations(): HasMany
    {
        return $this->hasMany(EtStation::class, 'event_id')->orderBy('sequence');
    }

    /** @return HasManyThrough<EtScanPoint, EtStation, $this> */
    public function scanPoints(): HasManyThrough
    {
        return $this->hasManyThrough(EtScanPoint::class, EtStation::class, 'event_id', 'station_id');
    }

    /** @return HasMany<EtAccess, $this> */
    public function access(): HasMany
    {
        return $this->hasMany(EtAccess::class, 'event_id');
    }

    /** @return HasMany<EtParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(EtParticipant::class, 'event_id');
    }

    /** @return HasMany<EtScanLog, $this> */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(EtScanLog::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
