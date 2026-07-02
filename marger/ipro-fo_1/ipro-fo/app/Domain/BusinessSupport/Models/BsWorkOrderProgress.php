<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsWorkOrderProgress extends Model
{
    protected $table = 'bs_work_order_progress';
    protected $fillable = [
        'work_order_id', 'progress_date', 'description', 'progress_value', 'retention_amount',
        'ppn_amount', 'net_payable', 'status', 'paid_at', 'journal_id', 'pay_journal_id', 'created_by',
        'kpl_number', 'releaser', 'pic', 'no_manual', 'contractor_signer',
    ];
    protected $casts = [
        'progress_date' => 'date', 'paid_at' => 'date',
        'progress_value' => 'decimal:2', 'retention_amount' => 'decimal:2',
        'ppn_amount' => 'decimal:2', 'net_payable' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
}
