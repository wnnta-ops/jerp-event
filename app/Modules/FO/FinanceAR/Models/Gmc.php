<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GMC (Gimmick / FREE SPR) — biaya promo developer untuk customer (mengikat SPR/unit/
 * customer via kontrak). BUKAN tagihan customer. Dikirim ke Posting Invoice BO
 * (Sumber: GMC) lewat tombol "Posting BO". no_gmc = GMC-... (numerator).
 */
class Gmc extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_gmc';

    protected $fillable = [
        'tenant_id', 'no_gmc', 'tgl', 'kontrak_id', 'customer_id',
        'nama', 'nilai', 'keterangan', 'status',
    ];

    protected $casts = [
        'tgl' => 'date',
        'nilai' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_gmc)) {
                $m->no_gmc = app(NumeratorService::class)->next('GMC');
            }
        });
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rincian komponen (opsional). Bila diisi, GMC pecah per komponen saat Posting BO. */
    public function items(): HasMany
    {
        return $this->hasMany(GmcItem::class)->orderBy('urutan')->orderBy('id');
    }
}
