<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit log KPR berbasis natural key (reference_code). Immutable. */
class ChangeLog extends Model
{
    public $timestamps = false;

    protected $table = 'bankloan_change_logs';

    protected $fillable = [
        'action', 'reference_code', 'reference_name', 'reference_number',
        'before', 'after', 'causer_id', 'created_at',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
}
