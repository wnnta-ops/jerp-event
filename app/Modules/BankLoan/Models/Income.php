<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use SoftDeletes;

    protected $table = 'incomes';

    protected $fillable = ['name'];
}
