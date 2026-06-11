<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Master checklist persyaratan dokumen KPR (KTP, NPWP, slip gaji, dll). */
class Requirement extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_requirements';

    protected $fillable = ['code', 'name', 'description'];
}
