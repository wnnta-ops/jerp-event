<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiCheckingFile extends Model
{
    protected $table = 'bankloan_bi_checking_files';

    protected $fillable = ['bi_checking_id', 'file_path', 'original_file_name', 'uploaded_at'];

    protected $casts = ['uploaded_at' => 'datetime'];

    public function biChecking(): BelongsTo
    {
        return $this->belongsTo(BiChecking::class, 'bi_checking_id');
    }
}
