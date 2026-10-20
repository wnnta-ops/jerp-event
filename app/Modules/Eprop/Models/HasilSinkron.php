<?php

namespace App\Modules\Eprop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu dokumen pada satu kali sinkron eProperty → J-ERP (lihat MesinSinkron). */
class HasilSinkron extends Model
{
    protected $table = 'eprop_hasil';

    protected $guarded = ['id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'decimal:2'];

    public function jalan(): BelongsTo
    {
        return $this->belongsTo(JalanSinkron::class, 'jalan_id');
    }
}
