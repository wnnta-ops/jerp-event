<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bilyet Giro / Cek diterima. status: NORMAL/JATUH_TEMPO/CAIR/BERMASALAH/DIGANTI. */
class Bg extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_bg';

    protected $fillable = [
        'tenant_id', 'no_bg', 'tgl_bg', 'tgl_jatuh_tempo', 'bank_id', 'customer_id',
        'tts_id', 'amount', 'pengelola', 'status', 'bg_pengganti_id', 'keterangan',
    ];

    protected $casts = [
        'tgl_bg' => 'date',
        'tgl_jatuh_tempo' => 'date',
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

    public function pengganti(): BelongsTo
    {
        return $this->belongsTo(self::class, 'bg_pengganti_id');
    }
}
