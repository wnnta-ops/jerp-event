<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kurs Sistem — nilai tukar per tanggal (1 unit mata uang = rate × IDR).
 */
class BsExchangeRate extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_exchange_rates';

    protected $fillable = ['tenant_id', 'currency_code', 'rate_date', 'rate', 'note'];

    protected $casts = ['rate_date' => 'date', 'rate' => 'decimal:6'];
}
