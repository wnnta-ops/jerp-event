<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup NUP — "Jenis Tipe NUP" master per project. Nilai (UTJ) mengikat saat
 * pendaftaran publik dan jadi nominal invoice Xendit.
 */
class NupTipe extends TenantAwareModel
{
    protected $table = 'fo_nup_tipe';

    protected $fillable = [
        'tenant_id', 'project_id', 'nama', 'nilai_utj', 'keterangan', 'is_active',
    ];

    protected $casts = [
        'nilai_utj' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
