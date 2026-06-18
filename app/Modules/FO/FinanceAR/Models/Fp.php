<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Faktur Penjualan — otomatis per kontrak saat unit TERJUAL (juga bisa di-link TTS/kwitansi). */
class Fp extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_fp';

    protected $fillable = [
        'tenant_id', 'no_fp', 'tgl_terima', 'tgl_faktur', 'tts_id', 'kontrak_id',
        'no_kwitansi', 'project_id', 'status', 'keterangan',
    ];

    protected $casts = ['tgl_terima' => 'date', 'tgl_faktur' => 'date'];

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
