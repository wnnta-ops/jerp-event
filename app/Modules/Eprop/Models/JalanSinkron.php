<?php

namespace App\Modules\Eprop\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu kali sinkron eProperty → J-ERP. */
class JalanSinkron extends Model
{
    protected $table = 'eprop_jalan';

    protected $guarded = ['id'];

    protected $casts = ['ringkasan' => 'array', 'kering' => 'boolean', 'mulai' => 'datetime', 'selesai' => 'datetime'];
}
