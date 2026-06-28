<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;

class KprSp3k extends Model
{
    protected $table = 'kpr_sp3k';

    protected $fillable = [
        'kpr_application_id', 'sp3k_number', 'sp3k_date', 'approved_amount',
        'interest_pct', 'tenor_month', 'status', 'expires_at',
    ];

    protected $casts = [
        'sp3k_date'       => 'date',
        'expires_at'      => 'date',
        'approved_amount' => 'decimal:2',
        'interest_pct'    => 'decimal:2',
    ];
}
