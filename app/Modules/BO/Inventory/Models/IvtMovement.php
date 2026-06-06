<?php

namespace App\Modules\BO\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvtMovement extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_ivt_movements';

    /** Movement types that increase stock. */
    public const MASUK_TYPES = ['BBB', 'BPB'];

    /** Movement types that decrease stock. */
    public const KELUAR_TYPES = ['BKB', 'FPB'];

    protected $fillable = [
        'tenant_id', 'no_movement', 'jenis', 'tgl', 'item_id', 'lokasi_id',
        'arah', 'qty', 'nilai', 'ref_dokumen', 'status', 'applied', 'keterangan',
        'approved_by', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'qty' => 'decimal:2',
        'nilai' => 'decimal:2',
        'applied' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(IvtItem::class, 'item_id');
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(IvtLocation::class, 'lokasi_id');
    }

    public static function arahFor(string $jenis): string
    {
        return in_array($jenis, self::MASUK_TYPES, true) ? 'MASUK' : 'KELUAR';
    }

    public function requiresApproval(): bool
    {
        return $this->jenis === 'FPB';
    }
}
