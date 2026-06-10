<?php

namespace App\Modules\FO\Komisi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biaya (TipeTarif) yang dipotong pada satu Skema Komisi. */
class SkemaKomisiBiaya extends Model
{
    protected $table = 'fo_skema_komisi_biaya';

    protected $fillable = [
        'skema_komisi_id', 'tipe_tarif_id',
    ];

    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKomisi::class, 'skema_komisi_id');
    }

    public function tipeTarif(): BelongsTo
    {
        return $this->belongsTo(TipeTarif::class, 'tipe_tarif_id');
    }
}
