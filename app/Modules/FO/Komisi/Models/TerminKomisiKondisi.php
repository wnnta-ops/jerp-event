<?php

namespace App\Modules\FO\Komisi\Models;

use App\Modules\FO\Adminjual\Models\SalesLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kondisi pencairan satu termin komisi (per level): % cair + syarat cair. */
class TerminKomisiKondisi extends Model
{
    protected $table = 'fo_termin_komisi_kondisi';

    protected $fillable = [
        'termin_komisi_id', 'sales_level_id', 'nama', 'persen_cair',
        'use_lunas', 'persen_lunas', 'use_bf', 'persen_bf',
        'use_dp', 'persen_dp', 'use_ang', 'persen_ang',
        'syarat_ppjb', 'syarat_ajb', 'syarat_akad', 'tipe_syarat', 'urutan',
    ];

    protected $casts = [
        'persen_cair' => 'decimal:4',
        'use_lunas' => 'boolean', 'persen_lunas' => 'decimal:4',
        'use_bf' => 'boolean', 'persen_bf' => 'decimal:4',
        'use_dp' => 'boolean', 'persen_dp' => 'decimal:4',
        'use_ang' => 'boolean', 'persen_ang' => 'decimal:4',
        'syarat_ppjb' => 'boolean', 'syarat_ajb' => 'boolean', 'syarat_akad' => 'boolean',
        'urutan' => 'integer',
    ];

    public function termin(): BelongsTo
    {
        return $this->belongsTo(TerminKomisi::class, 'termin_komisi_id');
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
