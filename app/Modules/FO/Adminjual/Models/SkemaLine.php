<?php

namespace App\Modules\FO\Adminjual\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkemaLine extends Model
{
    protected $table = 'fo_skema_lines';

    protected $fillable = [
        'skema_id', 'urutan', 'tipe', 'nama_termin',
        'basis', 'persen', 'nominal',
        'jatuh_tempo_hari', 'jadwal_basis', 'jadwal_nilai', 'setelah',
        'flag_bf', 'flag_kpr', 'keterangan',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'persen' => 'decimal:6',
        'nominal' => 'decimal:2',
        'jatuh_tempo_hari' => 'integer',
        'jadwal_nilai' => 'integer',
        'setelah' => 'integer',
        'flag_bf' => 'boolean',
        'flag_kpr' => 'boolean',
    ];

    public function skema(): BelongsTo
    {
        return $this->belongsTo(Skema::class);
    }
}
