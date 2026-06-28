<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsArKuitansi extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ar_kuitansis';
    protected $fillable = [
        'tenant_id', 'kuitansi_number', 'kuitansi_date', 'customer_id', 'cash_account_id',
        'alat_transaksi', 'kasir', 'manual_no', 'description', 'total', 'status',
        'journal_id', 'void_journal_id', 'voided_at', 'created_by',
    ];
    protected $casts = ['kuitansi_date' => 'date', 'voided_at' => 'date', 'total' => 'decimal:2'];

    public const ALAT = ['tunai' => 'Tunai', 'transfer' => 'Transfer', 'giro' => 'Cek/Giro'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
    public function lines(): HasMany { return $this->hasMany(BsArKuitansiLine::class, 'kuitansi_id'); }
}
