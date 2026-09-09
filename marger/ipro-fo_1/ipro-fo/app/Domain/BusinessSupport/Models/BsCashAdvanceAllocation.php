<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris ALOKASI KASBON — sekali pengeluaran atas bon yang dibayar lewat Debit VA.
 *
 * Tiap baris berjurnal sendiri (Dr Uang Muka Operasional / Cr bank pengeluaran) dan sekaligus
 * menjadi "Pemakaian" kartunya pada bulan tanggal alokasinya. Lihat CashAdvanceAllocationService.
 */
class BsCashAdvanceAllocation extends Model
{
    use HasTenant;

    protected $table = 'bs_cash_advance_allocations';

    protected $fillable = [
        'tenant_id', 'cash_advance_id', 'credit_card_id', 'alloc_date',
        'description', 'amount', 'cash_account_id', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'alloc_date' => 'date:Y-m-d',
        'amount'     => 'decimal:2',
    ];

    public function advance(): BelongsTo { return $this->belongsTo(BsCashAdvance::class, 'cash_advance_id'); }

    public function card(): BelongsTo { return $this->belongsTo(BsCreditCard::class, 'credit_card_id'); }

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }

    public function journal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'journal_id'); }
}
