<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsJournal extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_journals';
    protected $fillable = [
        'tenant_id', 'cost_center_id', 'journal_number', 'journal_date', 'period_year', 'period_month',
        'source', 'reference', 'description', 'status', 'total_debit', 'total_credit', 'created_by',
        'posted_at', 'finalized_at', 'approved_by',
    ];
    protected $casts = [
        'journal_date' => 'date', 'posted_at' => 'datetime', 'finalized_at' => 'datetime',
        'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2',
        'period_year' => 'integer', 'period_month' => 'integer',
    ];

    /** Siklus: draft (Baru) → final (Final) → posted (Approved/efektif di GL). */
    public const STATUSES = [
        'draft'  => 'Baru',
        'final'  => 'Final',
        'posted' => 'Approved',
    ];

    public const SOURCES = [
        'memorial'   => 'Memorial',
        'saldo_awal' => 'Saldo Awal',
        'ar'         => 'Piutang (AR)',
        'ap'         => 'Hutang (AP)',
        'cash'       => 'Kas/Bank',
        'inventory'  => 'Persediaan',
    ];

    public function lines(): HasMany { return $this->hasMany(BsJournalLine::class, 'journal_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
}
