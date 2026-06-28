<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Konfirmasi Saldo Awal — penanda bahwa saldo awal tahun {year} sudah ditinjau & dikunci.
 */
class BsOpeningConfirmation extends Model
{
    use HasTenant;

    protected $table = 'bs_opening_confirmations';

    protected $fillable = ['tenant_id', 'year', 'total_debit', 'total_credit', 'balanced', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['year' => 'integer', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'balanced' => 'boolean', 'confirmed_at' => 'datetime'];
}
