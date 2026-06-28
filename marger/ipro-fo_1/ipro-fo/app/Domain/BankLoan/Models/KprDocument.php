<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;

class KprDocument extends Model
{
    protected $table = 'kpr_documents';

    protected $fillable = ['kpr_application_id', 'type', 'file_path', 'status'];
}
