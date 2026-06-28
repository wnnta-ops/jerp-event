<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Detil Term SPK (jadwal/rencana termin pembayaran). */
class BsWorkOrderTerm extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_terms';
    protected $fillable = ['tenant_id', 'work_order_id', 'seq', 'description', 'percentage', 'amount', 'due_date'];
    protected $casts = ['seq' => 'integer', 'percentage' => 'decimal:2', 'amount' => 'decimal:2', 'due_date' => 'date'];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
}
