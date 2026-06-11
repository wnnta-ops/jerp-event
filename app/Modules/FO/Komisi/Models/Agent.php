<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Adminjual\Models\SalesType;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends TenantAwareModel
{
    protected $table = 'fo_agents';

    protected static function booted(): void
    {
        parent::booted();
        // No. Sales otomatis (5 digit zero-padded) bila kosong.
        static::creating(function (self $m) {
            if (empty($m->code)) {
                $m->code = app(NumeratorService::class)->next('SALES');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'inisial', 'name', 'agency_name', 'jenis', 'is_arebi',
        'sales_type_id', 'sales_level_id', 'atasan_id',
        'phone', 'whatsapp', 'email', 'alamat', 'npwp',
        'rekening_bank', 'rekening_number', 'rekening_holder',
        'komisi_persen_default', 'is_active', 'keterangan',
    ];

    /**
     * Label tampil "Nama Sales - Agensi Properti" (mis. "Danang - Uhome").
     * Dipakai di dropdown NUP, SPRS, dan tanda tangan SPR. Tanpa agensi → nama saja.
     */
    public function getSalesLabelAttribute(): string
    {
        return trim($this->name.($this->agency_name ? ' - '.$this->agency_name : ''));
    }

    protected $casts = [
        'komisi_persen_default' => 'decimal:2',
        'is_active' => 'boolean',
        'is_arebi' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesType(): BelongsTo
    {
        return $this->belongsTo(SalesType::class);
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class);
    }

    /** Atasan langsung di hierarki sales (mis. KOOR untuk SALES). */
    public function atasan(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'atasan_id');
    }

    public function bawahan(): HasMany
    {
        return $this->hasMany(Agent::class, 'atasan_id');
    }

    public function komisi(): HasMany
    {
        return $this->hasMany(Komisi::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }
}
