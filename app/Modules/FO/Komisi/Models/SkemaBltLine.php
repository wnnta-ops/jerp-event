<?php

namespace App\Modules\FO\Komisi\Models;

use App\Modules\FO\Adminjual\Models\SalesLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris rumus BLT per level (master Level Sales) untuk satu Skema BLT. */
class SkemaBltLine extends Model
{
    protected $table = 'fo_skema_blt_line';

    protected $fillable = [
        'skema_blt_id', 'sales_level_id', 'tipe_tarif', 'tarif', 'potong_komisi', 'urutan',
    ];

    protected $casts = [
        'tarif' => 'decimal:4',
        'potong_komisi' => 'boolean',
        'urutan' => 'integer',
    ];

    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaBlt::class, 'skema_blt_id');
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
