<?php

namespace App\Modules\BO\PurchaseRequest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrLine extends Model
{
    protected $table = 'bo_pr_lines';

    protected $fillable = ['pr_id', 'urutan', 'deskripsi', 'qty', 'satuan', 'harga_estimasi', 'subtotal'];

    protected $casts = [
        'urutan' => 'integer',
        'qty' => 'decimal:2',
        'harga_estimasi' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }
}
