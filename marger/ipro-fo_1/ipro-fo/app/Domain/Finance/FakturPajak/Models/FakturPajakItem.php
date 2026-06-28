<?php

declare(strict_types=1);

namespace App\Domain\Finance\FakturPajak\Models;

use Illuminate\Database\Eloquent\Model;

class FakturPajakItem extends Model
{
    protected $fillable = ['faktur_pajak_id', 'description', 'qty', 'unit_price', 'total'];
    protected $casts    = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'total' => 'decimal:2'];
}
