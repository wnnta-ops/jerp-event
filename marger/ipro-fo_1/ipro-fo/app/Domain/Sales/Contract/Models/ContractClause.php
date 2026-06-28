<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Models;

use Illuminate\Database\Eloquent\Model;

class ContractClause extends Model
{
    protected $fillable = ['contract_id', 'clause_no', 'title', 'body'];
}
