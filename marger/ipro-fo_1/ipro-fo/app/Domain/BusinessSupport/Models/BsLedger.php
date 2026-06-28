<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ledger — dimensi analitik jurnal (eProperty "Ledger").
 */
class BsLedger extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ledgers';

    protected $fillable = ['tenant_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
