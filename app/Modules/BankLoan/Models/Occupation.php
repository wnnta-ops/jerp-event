<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Occupation extends Model
{
    use SoftDeletes;

    protected $table = 'occupations';

    protected $fillable = ['name'];
}
