<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan KPR (Finance) berbasis SPR — murni pengajuan PEMBAYARAN biaya proses
 * KPR (free BPHTB/HT sebagian/KPR/notaris/sisa HT). Tidak mengubah SPR sama sekali;
 * pencairan KPR & pelunasan tagihan SPR ditangani modul BankLoan. Biaya ada di
 * {@see KprPengajuanBiaya}.
 */
class KprPengajuan extends TenantAwareModel
{
    protected $table = 'fo_kpr_pengajuan';

    protected $fillable = [
        'tenant_id',
        'no_pengajuan',
        'kontrak_id',
        'tgl_pengajuan',
        'status',
        'keterangan',
        'created_by',
    ];

    protected $casts = [
        'tgl_pengajuan' => 'date',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function biaya(): HasMany
    {
        return $this->hasMany(KprPengajuanBiaya::class, 'pengajuan_id');
    }
}
