<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Master tipe retensi (mis. "Sertifikat", "PPJB Selesai"). */
class RetentionCategory extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_retention_categories';

    protected $fillable = ['name', 'description'];
}
