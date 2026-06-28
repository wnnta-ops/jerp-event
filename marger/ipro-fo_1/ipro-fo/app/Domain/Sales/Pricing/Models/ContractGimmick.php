<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Models;

use App\Domain\Sales\Contract\Models\Contract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $contract_id
 * @property int $gimmick_id
 * @property int|null $gimmick_package_id
 * @property int $qty
 * @property float $harga_satuan
 * @property float $total_harga
 * @property \Carbon\Carbon|null $target_lunas
 * @property bool $diberikan
 * @property bool $diterima
 * @property \Carbon\Carbon|null $tgl_diterima
 */
class ContractGimmick extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contract_gimmicks';

    protected $fillable = [
        'contract_id', 'gimmick_id', 'gimmick_package_id',
        'value', 'qty', 'harga_satuan', 'total_harga', 'target_lunas',
        'diberikan', 'diterima', 'tgl_diterima', 'notes', 'created_by',
    ];

    protected $casts = [
        'value'        => 'decimal:2',
        'qty'          => 'integer',
        'harga_satuan' => 'decimal:2',
        'total_harga'  => 'decimal:2',
        'target_lunas' => 'date',
        'tgl_diterima' => 'date',
        'diberikan'    => 'boolean',
        'diterima'     => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function gimmick(): BelongsTo
    {
        return $this->belongsTo(Gimmick::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(GimmickPackage::class, 'gimmick_package_id');
    }

    /**
     * Apakah gimmick bisa di-claim customer? Cek target_lunas vs kontrak.
     */
    public function isClaimable(Contract $contract): bool
    {
        if (! $this->target_lunas) return true;
        // Logic: check kontrak sudah lunas + sebelum target_lunas
        // For now simplified — extend nanti dengan integrasi payment_schedules
        return true;
    }
}
