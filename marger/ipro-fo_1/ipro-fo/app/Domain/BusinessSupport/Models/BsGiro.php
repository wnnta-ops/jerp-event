<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsGiro extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_giros';
    protected $fillable = [
        'tenant_id', 'giro_number', 'receive_date', 'due_date', 'bank_name', 'cheque_number', 'drawer',
        'amount', 'giro_account_id', 'counter_account_id', 'cash_account_id', 'status', 'settled_date',
        'bounce_reason', 'receive_journal_id', 'settle_journal_id', 'created_by', 'source', 'source_ref',
    ];
    protected $casts = [
        'receive_date' => 'date', 'due_date' => 'date', 'settled_date' => 'date', 'amount' => 'decimal:2',
    ];

    public const STATUSES = ['in_hand' => 'Di Tangan', 'cleared' => 'Cair', 'bounced' => 'Tolak'];

    public function counterAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'counter_account_id'); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
}
