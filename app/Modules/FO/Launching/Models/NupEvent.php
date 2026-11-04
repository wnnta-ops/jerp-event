<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NupEvent extends TenantAwareModel
{
    protected $table = 'fo_nup_events';

    protected $fillable = [
        'tenant_id', 'project_id', 'lokasi_id', 'code', 'name',
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

    /** Cluster event — Pilih Unit menolak NUP event ini di cluster lain. Null = semua cluster. */
    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(NupRegistration::class, 'nup_event_id');
    }

    /**
     * Status efektif diturunkan dari tanggal buka/tutup pendaftaran.
     * State terminal (EXECUTED/BATAL) tetap dihormati bila kelak di-set.
     */
    public function getEffectiveStatusAttribute(): string
    {
        if (in_array($this->status, ['EXECUTED', 'BATAL'], true)) {
            return $this->status;
        }

        $now = now();

        if ($this->open_registration_at === null) {
            return 'DRAFT';
        }

        if ($now->lt($this->open_registration_at)) {
            return 'DRAFT';
        }

        if ($this->close_registration_at !== null && $now->gt($this->close_registration_at)) {
            return 'CLOSED';
        }

        return 'OPEN';
    }

    /**
     * Event yang SEDANG buka pendaftaran (turunan waktu, bukan kolom status).
     */
    public function scopeOpenNow($query)
    {
        $now = now();

        return $query->whereNotIn('status', ['EXECUTED', 'BATAL'])
            ->whereNotNull('open_registration_at')
            ->where('open_registration_at', '<=', $now)
            ->where(fn ($w) => $w->whereNull('close_registration_at')->orWhere('close_registration_at', '>=', $now));
    }
}
