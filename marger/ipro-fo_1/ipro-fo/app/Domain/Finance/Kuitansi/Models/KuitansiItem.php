<?php

declare(strict_types=1);

namespace App\Domain\Finance\Kuitansi\Models;

use Illuminate\Database\Eloquent\Model;

class KuitansiItem extends Model
{
    protected $fillable = ['kuitansi_id', 'description', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];
}
