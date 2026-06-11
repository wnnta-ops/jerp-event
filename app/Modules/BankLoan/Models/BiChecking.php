<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** BI Checking — cek riwayat kredit di Bank Indonesia. result: CLEAR/NEGATIVE/POSITIVE/PENDING. */
class BiChecking extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_bi_checking';

    protected $fillable = ['contract_id', 'result', 'checked_at', 'due_date', 'notes'];

    protected $casts = ['checked_at' => 'date', 'due_date' => 'date'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(BiCheckingFile::class, 'bi_checking_id');
    }
}
