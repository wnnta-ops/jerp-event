<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peserta = SATU NUP di satu event. Customer dengan beberapa NUP punya beberapa
 * peserta yang antre masing-masing.
 */
class EtParticipant extends TenantAwareModel
{
    protected $table = 'et_participants';

    public const ACTIVE = 'active';

    public const FINISHED = 'finished';

    public const LEFT = 'left';

    public const CANCELLED = 'cancelled';

    public const WAITING = 'waiting';

    public const IN_SERVICE = 'in_service';

    public const DONE = 'done';

    protected $fillable = [
        'tenant_id', 'event_id', 'fo_nup_registration_id', 'no_nup', 'customer_code',
        'name_snapshot', 'phone_snapshot', 'checked_in_at', 'finished_at', 'status',
        'current_station_id', 'current_state',
    ];

    protected $casts = [
        'fo_nup_registration_id' => 'integer',
        'checked_in_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<EtEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EtEvent::class, 'event_id');
    }

    /** @return BelongsTo<EtStation, $this> */
    public function currentStation(): BelongsTo
    {
        return $this->belongsTo(EtStation::class, 'current_station_id');
    }

    /** @return HasMany<EtVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(EtVisit::class, 'participant_id');
    }
}
