<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;

class BankProduct extends Model
{
    protected $fillable = ['bank_partner_id', 'code', 'name', 'interest_pct', 'max_tenor_month', 'is_active'];
    protected $casts    = ['is_active' => 'boolean', 'interest_pct' => 'decimal:2'];
}
