<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** Satu baris "Proses KPR per Cluster (Status Unit)" — diisi lewat import Exsum. */
class KprClusterStatus extends Model
{
    use HasTenant;

    protected $table = 'kpr_cluster_status';

    protected $fillable = [
        'tenant_id', 'urutan', 'label', 'total_unit', 'realisasi', 'kpr', 'cash',
        'proses', 'open_stock', 'hold', 'escrow', 'catatan', 'created_by',
    ];

    protected $casts = [
        'urutan' => 'integer', 'total_unit' => 'integer', 'realisasi' => 'integer',
        'kpr' => 'integer', 'cash' => 'integer', 'proses' => 'integer',
        'open_stock' => 'integer', 'hold' => 'integer',
    ];
}
