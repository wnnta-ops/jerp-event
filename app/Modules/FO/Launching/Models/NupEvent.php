<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NupEvent extends TenantAwareModel
{
    protected $table = 'fo_nup_events';

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name',
        'tgl_launching', 'open_registration_at', 'close_registration_at',
        'kuota', 'booking_fee', 'status', 'keterangan', 'tipe_nup_options',
    ];

    protected $casts = [
        'tgl_launching' => 'date',
        'open_registration_at' => 'datetime',
        'close_registration_at' => 'datetime',
        'kuota' => 'integer',
        'booking_fee' => 'decimal:2',
        'tipe_nup_options' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(NupRegistration::class, 'nup_event_id');
    }
}
