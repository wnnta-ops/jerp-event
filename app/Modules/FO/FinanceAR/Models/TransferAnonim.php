<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Transfer masuk belum teridentifikasi. status: BARU/TERIDENTIFIKASI/SELESAI → jadi TTS. */
class TransferAnonim extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_transfer_anonim';

    protected $fillable = [
        'tenant_id', 'tgl_transfer', 'bank_id', 'amount', 'keterangan_bank',
        'customer_id', 'tts_id', 'status', 'keterangan',
    ];

    protected $casts = [
        'tgl_transfer' => 'date',
        'amount' => 'decimal:2',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }
}
