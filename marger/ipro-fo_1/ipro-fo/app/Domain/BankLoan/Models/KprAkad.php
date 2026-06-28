<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;

class KprAkad extends Model
{
    protected $table = 'kpr_akads';

    protected $fillable = ['kpr_application_id', 'akad_at', 'place', 'notary', 'status'];
    protected $casts    = ['akad_at' => 'datetime'];
}
