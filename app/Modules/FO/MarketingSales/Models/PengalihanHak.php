<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengalihan Hak — pindah customer kontrak (suami→istri / orang lain). Setiap record =
 * satu peristiwa pengalihan (history): customer lama → customer baru + biaya admin + scan.
 */
class PengalihanHak extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_pengalihan_hak';

    protected $fillable = [
        'tenant_id', 'no_pengalihan', 'kontrak_id', 'customer_lama_id', 'customer_baru_id',
        'tgl_pengalihan', 'biaya_admin', 'file_scan_path', 'sales_snapshot', 'status', 'keterangan',
    ];

    protected $casts = [
        'tgl_pengalihan' => 'date',
        'biaya_admin' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_pengalihan)) {
                $m->no_pengalihan = app(NumeratorService::class)->next('PH');
            }
        });
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function customerLama(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_lama_id');
    }

    public function customerBaru(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_baru_id');
    }
}
