<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pos (checkpoint) di event. Urutan lewat `sequence`; pos pertama selalu
 * fo_checkin (Aktivasi NUP di FO, hanya dibaca), pos terakhir finish.
 * is_optional = pos boleh dilewati (pos terakhir tidak boleh opsional).
 */
class EtStation extends TenantAwareModel
{
    protected $table = 'et_stations';

    public const FO_CHECKIN = 'fo_checkin';

    public const NORMAL = 'normal';

    public const FINISH = 'finish';

    protected $fillable = [
        'tenant_id', 'event_id', 'code', 'name', 'sequence', 'type', 'is_optional', 'target_minutes',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'target_minutes' => 'integer',
        'is_optional' => 'boolean',
    ];

    public function isFoCheckin(): bool
    {
        return $this->type === self::FO_CHECKIN;
    }

    public function isFinish(): bool
    {
        return $this->type === self::FINISH;
    }

    /** @return BelongsTo<EtEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EtEvent::class, 'event_id');
    }

    /** @return HasMany<EtScanPoint, $this> */
    public function scanPoints(): HasMany
    {
        return $this->hasMany(EtScanPoint::class, 'station_id');
    }

    /** @return HasMany<EtVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(EtVisit::class, 'station_id');
    }
}
