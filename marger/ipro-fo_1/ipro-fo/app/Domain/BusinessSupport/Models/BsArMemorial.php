<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsArMemorial extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ar_memorials';
    protected $fillable = [
        'tenant_id', 'memorial_number', 'memorial_date', 'customer_id', 'ar_invoice_id', 'trx_type',
        'counter_account_id', 'receivable_account_id', 'amount', 'description', 'kasir', 'manual_no',
        'is_writeoff', 'journal_id', 'created_by',
    ];
    protected $casts = ['memorial_date' => 'date', 'amount' => 'decimal:2', 'is_writeoff' => 'boolean'];

    public const TYPES = ['penambahan' => 'Penambahan Nilai', 'penghapusan' => 'Penghapusan Nilai'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function counterAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'counter_account_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(BsArInvoice::class, 'ar_invoice_id'); }
}
