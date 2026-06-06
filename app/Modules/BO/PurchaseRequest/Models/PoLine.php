<?php

namespace App\Modules\BO\PurchaseRequest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoLine extends Model
{
    protected $table = 'bo_po_lines';

    protected $fillable = ['po_id', 'urutan', 'deskripsi', 'qty', 'satuan', 'harga', 'subtotal'];

    protected $casts = [
        'urutan' => 'integer',
        'qty' => 'decimal:2',
        'harga' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }
}
