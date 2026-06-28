<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsCogsRecognition extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cogs_recognitions';
    protected $fillable = [
        'tenant_id', 'cogs_number', 'recognition_date', 'reference', 'description',
        'inventory_account_id', 'cogs_account_id', 'cost_center_id', 'amount', 'journal_id', 'created_by',
    ];
    protected $casts = ['recognition_date' => 'date', 'amount' => 'decimal:2'];

    public function inventoryAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'inventory_account_id'); }
}
