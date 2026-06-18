<?php

namespace App\Modules\FO\Collection\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan surat penagihan (reminder / SP I-III) yang dikirim ke customer.
 * Lihat KontrakTagihan untuk sumber jatuh tempo & sisa AR.
 */
class CollectionNotice extends TenantAwareModel
{
    protected $table = 'fo_collection_notices';

    protected $fillable = [
        'tenant_id',
        'kontrak_id',
        'kontrak_tagihan_id',
        'no_surat',
        'jenis',
        'channel',
        'status',
        'penerima',
        'subjek',
        'isi',
        'hari_telat',
        'dikirim_pada',
        'created_by',
    ];

    protected $casts = [
        'hari_telat' => 'integer',
        'dikirim_pada' => 'datetime',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(KontrakTagihan::class, 'kontrak_tagihan_id');
    }

    /** Label ramah untuk jenis surat. */
    public function getJenisLabelAttribute(): string
    {
        return match ($this->jenis) {
            'REMINDER' => 'Reminder',
            'SP1' => 'Surat Peringatan I',
            'SP2' => 'Surat Peringatan II',
            'SP3' => 'Surat Peringatan III',
            default => $this->jenis,
        };
    }
}
