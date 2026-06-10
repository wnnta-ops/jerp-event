<?php

namespace App\Modules\FO\Komisi\Models;

use App\Modules\FO\Adminjual\Models\SalesLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rumus komisi per level (master Level Sales) untuk satu Skema Komisi. */
class SkemaKomisiLine extends Model
{
    protected $table = 'fo_skema_komisi_line';

    protected $fillable = [
        'skema_komisi_id', 'sales_level_id', 'tipe_tarif', 'tarif', 'urutan',
    ];

    protected $casts = [
        'tarif' => 'decimal:4',
        'urutan' => 'integer',
    ];

    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKomisi::class, 'skema_komisi_id');
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
