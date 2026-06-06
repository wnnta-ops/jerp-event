<?php

namespace App\Modules\BO\GeneralLedger\Models;

use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JurnalHeader extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_jurnal_header';

    protected $fillable = [
        'tenant_id',
        'no_jurnal',
        'tgl_jurnal',
        'periode',
        'company_id',
        'jenis_dokumen',
        'no_dokumen',
        'keterangan',
        'status',
        'posted',
        'total_debet',
        'total_kredit',
        'source_type',
        'source_id',
        'reversal_of_id',
        'approved_by',
        'approved_at',
        'posted_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'tgl_jurnal' => 'date',
        'posted' => 'boolean',
        'total_debet' => 'decimal:2',
        'total_kredit' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function detil(): HasMany
    {
        return $this->hasMany(JurnalDetil::class, 'jurnal_id')->orderBy('urutan');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function isBalanced(): bool
    {
        return bccomp((string) $this->total_debet, (string) $this->total_kredit, 2) === 0;
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    public function scopePeriode($query, string $periode)
    {
        return $query->where('periode', $periode);
    }
}
