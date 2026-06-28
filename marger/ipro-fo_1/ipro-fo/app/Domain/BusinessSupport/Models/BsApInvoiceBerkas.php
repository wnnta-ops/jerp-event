<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsApInvoiceBerkas extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ap_invoice_berkas';
    protected $fillable = ['ap_invoice_id', 'berkas_id', 'is_complete'];
    protected $casts = ['is_complete' => 'boolean'];

    public function berkas(): BelongsTo { return $this->belongsTo(BsApBerkas::class, 'berkas_id'); }
}
