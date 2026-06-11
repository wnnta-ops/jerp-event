<?php

namespace App\Modules\FO\Komisi\Models;

use App\Modules\FO\Adminjual\Models\SalesLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kondisi pencairan Termin BLT (syarat BF/DP/ANG/AJB + tipe syarat). */
class TerminBltKondisi extends Model
{
    protected $table = 'fo_termin_blt_kondisi';

    protected $fillable = [
        'termin_blt_id', 'sales_level_id', 'nama', 'persen_cair',
        'use_bf', 'tipe_bf', 'nilai_bf',
        'use_dp', 'tipe_dp', 'nilai_dp',
        'use_ang', 'tipe_ang', 'nilai_ang',
        'syarat_ajb', 'tipe_syarat', 'urutan',
    ];

    protected $casts = [
        'persen_cair' => 'decimal:2',
        'use_bf' => 'boolean', 'nilai_bf' => 'decimal:4',
        'use_dp' => 'boolean', 'nilai_dp' => 'decimal:4',
        'use_ang' => 'boolean', 'nilai_ang' => 'decimal:4',
        'syarat_ajb' => 'boolean',
        'urutan' => 'integer',
    ];

    public function termin(): BelongsTo
    {
        return $this->belongsTo(TerminBlt::class, 'termin_blt_id');
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
