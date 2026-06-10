<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Modules\FO\Adminjual\Models\Gimmick;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gimmick (free item/promo) yang melekat pada satu Kontrak. */
class KontrakGimmick extends Model
{
    protected $table = 'fo_kontrak_gimmick';

    protected $fillable = [
        'kontrak_id', 'gimmick_id', 'nama_barang', 'satuan',
        'diberikan', 'diterima', 'tgl_diterima',
        'qty', 'harga_satuan', 'total', 'keterangan', 'urutan',
    ];

    protected $casts = [
        'diberikan' => 'boolean',
        'diterima' => 'boolean',
        'tgl_diterima' => 'date',
        'qty' => 'integer',
        'harga_satuan' => 'decimal:2',
        'total' => 'decimal:2',
        'urutan' => 'integer',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class, 'kontrak_id');
    }

    public function gimmick(): BelongsTo
    {
        return $this->belongsTo(Gimmick::class, 'gimmick_id');
    }
}
