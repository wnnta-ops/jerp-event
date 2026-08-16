<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsCashAdvanceLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_cash_advance_lines';
    protected $fillable = ['cash_advance_id', 'expense_account_id', 'cost_center_id', 'description', 'amount'];
    protected $casts = ['amount' => 'decimal:2'];

    public function expenseAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'expense_account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
}
