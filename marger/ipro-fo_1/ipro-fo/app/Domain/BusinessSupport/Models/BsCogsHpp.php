<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS HPP RealCost per unit (metode persentase penyelesaian / POC). */
class BsCogsHpp extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_hpp';
    protected $fillable = [
        'tenant_id', 'cogs_id', 'period_year', 'period_month', 'project_id', 'location_id', 'unit_id', 'booking_id',
        'unit_code', 'customer', 'no_kontrak', 'luas_tanah', 'luas_bangunan', 'dpp', 'nilai_kontrak',
        'jumlah_pembayaran', 'persen_pelunasan', 'promo', 'blt', 'komisi',
        'hpp_tanah', 'hpp_bangunan', 'hpp_sarana', 'hpp_permit', 'hpp_tools', 'hpp_kpr', 'hpp_bphtb',
        'total_hpp', 'progress_pct', 'revenue_recognized', 'hpp_recognized', 'revenue_accum', 'hpp_accum',
        'status', 'journal_persediaan_id', 'journal_hpp_id', 'created_by',
    ];
    protected $casts = [
        'period_year' => 'integer', 'period_month' => 'integer',
        'luas_tanah' => 'decimal:2', 'luas_bangunan' => 'decimal:2', 'dpp' => 'decimal:2', 'nilai_kontrak' => 'decimal:2',
        'jumlah_pembayaran' => 'decimal:2', 'persen_pelunasan' => 'decimal:2', 'promo' => 'decimal:2', 'blt' => 'decimal:2', 'komisi' => 'decimal:2',
        'hpp_tanah' => 'decimal:2', 'hpp_bangunan' => 'decimal:2', 'hpp_sarana' => 'decimal:2', 'hpp_permit' => 'decimal:2',
        'hpp_tools' => 'decimal:2', 'hpp_kpr' => 'decimal:2', 'hpp_bphtb' => 'decimal:2', 'total_hpp' => 'decimal:2',
        'progress_pct' => 'decimal:2', 'revenue_recognized' => 'decimal:2', 'hpp_recognized' => 'decimal:2',
        'revenue_accum' => 'decimal:2', 'hpp_accum' => 'decimal:2',
    ];

    public const STATUSES = ['generated' => 'Generated', 'posted_persediaan' => 'Posted Persediaan', 'posted_hpp' => 'Posted HPP'];

    public function location(): BelongsTo { return $this->belongsTo(BsCogsLocation::class, 'location_id'); }
}
