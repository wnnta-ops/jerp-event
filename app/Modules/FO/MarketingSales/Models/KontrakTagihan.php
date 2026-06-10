<?php

namespace App\Modules\FO\MarketingSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris jadwal tagihan (editable) untuk satu Kontrak. */
class KontrakTagihan extends Model
{
    protected $table = 'fo_kontrak_tagihan';

    protected $fillable = [
        'kontrak_id', 'urutan', 'tipe', 'nama_tagihan',
        'jatuh_tempo', 'nilai', 'pelunasan', 'keterangan',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'jatuh_tempo' => 'date',
        'nilai' => 'decimal:2',
        'pelunasan' => 'decimal:2',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class, 'kontrak_id');
    }

    /** Sisa tagihan (nilai - pelunasan). */
    public function getSisaAttribute(): float
    {
        return (float) $this->nilai - (float) $this->pelunasan;
    }
}
