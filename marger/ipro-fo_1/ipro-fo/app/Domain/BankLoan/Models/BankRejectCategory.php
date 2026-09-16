<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master "Kategori Reject Bank" — daftar alasan penolakan KPR oleh bank
 * (KPR → Set Up → Kategori Reject Bank).
 */
class BankRejectCategory extends Model
{
    use HasTenant, SoftDeletes;

    // Ditulis eksplisit: tanpa ini Laravel menebak 'bank_reject_categories' dan diam-diam
    // menabrak tabel yang tidak ada — seluruh master KPR memakai awalan kpr_.
    protected $table = 'kpr_bank_reject_categories';

    protected $fillable = ['tenant_id', 'name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
