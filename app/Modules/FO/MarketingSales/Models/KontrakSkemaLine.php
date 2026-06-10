<?php

namespace App\Modules\FO\MarketingSales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Detail Skema Bayar (termin) per-kontrak — editable, untuk generate Jadwal Tagihan. */
class KontrakSkemaLine extends Model
{
    protected $table = 'fo_kontrak_skema_line';

    protected $fillable = [
        'kontrak_id', 'urutan', 'tipe', 'nama_termin', 'basis', 'persen', 'nominal',
        'jadwal_basis', 'jadwal_nilai', 'setelah', 'flag_bf', 'flag_kpr',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'persen' => 'decimal:4',
        'nominal' => 'decimal:2',
        'jadwal_nilai' => 'integer',
        'setelah' => 'integer',
        'flag_bf' => 'boolean',
        'flag_kpr' => 'boolean',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class, 'kontrak_id');
    }
}
