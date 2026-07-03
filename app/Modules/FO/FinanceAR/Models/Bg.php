<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Bilyet Giro / Cek diterima. status: NORMAL/JATUH_TEMPO/CAIR/BERMASALAH/DIGANTI. */
class Bg extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_bg';

    protected $fillable = [
        'tenant_id', 'no_bg', 'tgl_bg', 'tgl_jatuh_tempo', 'bank_id', 'bank_nama', 'customer_id',
        'tts_id', 'amount', 'pengelola', 'status', 'bg_pengganti_id', 'keterangan',
    ];

    protected $casts = [
        'tgl_bg' => 'date',
        'tgl_jatuh_tempo' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        // No. BG otomatis (BG-YYYYMM-#####) bila belum diisi.
        static::creating(function (self $m) {
            if (empty($m->no_bg)) {
                $m->no_bg = app(NumeratorService::class)->next('BG');
            }
        });
        // Auto-push ke BO "Cek Giro Masuk" (Di Tangan, belum jurnal) setelah tersimpan.
        static::created(function (self $m) {
            if (! config('ipro.sync_enabled', true)) {
                return;
            }
            try {
                app(\App\Modules\BO\IproBridge\Services\IproMasterSync::class)->pushGiro($m);
            } catch (\Throwable $e) {
                // Jangan gagalkan simpan BG di FO bila BO tak terjangkau.
            }
        });
    }

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
