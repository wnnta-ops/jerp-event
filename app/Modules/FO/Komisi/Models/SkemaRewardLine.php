<?php

namespace App\Modules\FO\Komisi\Models;

use App\Modules\FO\Adminjual\Models\SalesLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rumus reward per level (rentang target → reward) untuk satu Skema Reward. */
class SkemaRewardLine extends Model
{
    protected $table = 'fo_skema_reward_line';

    protected $fillable = [
        'skema_reward_id', 'sales_level_id', 'tipe_target', 'target_bawah', 'target_atas',
        'tipe_reward', 'reward_amount', 'reward_keterangan', 'urutan',
    ];

    protected $casts = [
        'target_bawah' => 'decimal:2',
        'target_atas' => 'decimal:2',
        'reward_amount' => 'decimal:2',
        'urutan' => 'integer',
    ];

    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaReward::class, 'skema_reward_id');
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
