<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Faktur Pajak — nomor dari DJP, di-link ke TTS/kwitansi. */
class Fp extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_fp';

    protected $fillable = [
        'tenant_id', 'no_fp', 'tgl_terima', 'tts_id', 'no_kwitansi',
        'project_id', 'status', 'keterangan',
    ];

    protected $casts = ['tgl_terima' => 'date'];

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
