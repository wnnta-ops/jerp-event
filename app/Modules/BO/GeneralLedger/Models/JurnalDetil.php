<?php

namespace App\Modules\BO\GeneralLedger\Models;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JurnalDetil extends Model
{
    protected $table = 'bo_jurnal_detil';

    protected $fillable = [
        'jurnal_id',
        'urutan',
        'kode_account',
        'cost_center_id',
        'debet',
        'kredit',
        'keterangan',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'debet' => 'decimal:2',
        'kredit' => 'decimal:2',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JurnalHeader::class, 'jurnal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'kode_account', 'kode');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
