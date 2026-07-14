<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master "Kategori Berkas Tidak Lengkap" — daftar alasan/kategori untuk catatan berkas
 * berstatus "Tidak Lengkap" pada tahap Collect Berkas (Inisiasi KPR).
 */
class BerkasIncompleteCategory extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'kpr_berkas_incomplete_categories';

    protected $fillable = ['tenant_id', 'name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
