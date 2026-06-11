<?php

namespace App\Modules\BankLoan\Models;

use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RR — Realisasi Retensi (pencairan dana retensi). number = RR-...
 * remaining_amount dilacak hingga 0 (semua retensi terlepas → kontrak boleh CLOSED).
 */
class RetentionRealization extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_retention_realizations';

    protected $fillable = [
        'number', 'retention_submission_id', 'realization_date',
        'realized_amount', 'remaining_amount',
    ];

    protected $casts = [
        'realization_date' => 'date',
        'realized_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = app(NumeratorService::class)->next('RR');
            }
        });
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(RetentionSubmission::class, 'retention_submission_id');
    }
}
