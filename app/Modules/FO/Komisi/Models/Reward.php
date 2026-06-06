<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reward extends TenantAwareModel
{
    protected $table = 'fo_rewards';

    protected $fillable = [
        'tenant_id', 'no_reward', 'tgl_reward', 'agent_id',
        'jenis', 'nama_reward', 'amount',
        'status', 'tgl_claim', 'keterangan',
    ];

    protected $casts = [
        'tgl_reward' => 'date',
        'tgl_claim' => 'date',
        'amount' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
