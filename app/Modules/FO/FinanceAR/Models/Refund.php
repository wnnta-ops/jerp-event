<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Refund — pengembalian dana. jenis: BOOKING (batal booking) / LEBIH_BAYAR (CB).
 * no_refund = RF-... (numerator). nilai_refund = nilai_kotor − biaya_admin.
 */
class Refund extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_refund';

    protected $fillable = [
        'tenant_id', 'no_refund', 'tgl_refund', 'jenis', 'customer_id', 'kontrak_id',
        'reservasi_id', 'nilai_kotor', 'biaya_admin', 'nilai_refund',
        'bank_id', 'rekening_tujuan', 'status', 'keterangan',
    ];

    protected $casts = [
        'tgl_refund' => 'date',
        'nilai_kotor' => 'decimal:2',
        'biaya_admin' => 'decimal:2',
        'nilai_refund' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_refund)) {
                $m->no_refund = app(NumeratorService::class)->next('REFUND');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function reservasi(): BelongsTo
    {
        return $this->belongsTo(Reservasi::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
