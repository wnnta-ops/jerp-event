<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pembagian biaya per cluster untuk satu baris jurnal (mutasi Rekening Ledger).
 */
class BsLedgerClusterSplit extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ledger_cluster_splits';

    protected $fillable = ['tenant_id', 'journal_line_id', 'cluster_id', 'amount', 'note'];

    protected $casts = ['amount' => 'decimal:2'];

    public function cluster(): BelongsTo { return $this->belongsTo(Cluster::class); }
}
