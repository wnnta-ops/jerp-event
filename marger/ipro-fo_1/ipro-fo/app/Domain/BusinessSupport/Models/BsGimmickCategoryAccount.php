<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu akun (debit atau kredit) pada sebuah Kategori Gimmick, berikut porsinya (%).
 * Satu kategori boleh punya beberapa akun di tiap sisi; jumlah porsi per sisi = 100.
 */
class BsGimmickCategoryAccount extends Model
{
    protected $table = 'bs_gimmick_category_accounts';

    protected $fillable = ['gimmick_category_id', 'side', 'account_id', 'portion', 'sort_order'];

    protected $casts = ['portion' => 'decimal:4'];

    public function category(): BelongsTo { return $this->belongsTo(BsGimmickCategory::class, 'gimmick_category_id'); }

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
}
