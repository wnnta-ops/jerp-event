<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master Kategori Retensi (mis. DAJAM, TANAH MATANG, PONDASI, TOPPING OFF, BAST + AJB).
 */
class RetentionCategory extends Model
{
    use HasTenant, SoftDeletes;

    /**
     * Pilihan "Sinkron dengan Master Data KPR" — kunci kolom Collection Retensi di layar itu.
     *
     * Kategori yang bersinkron ke grup yang sama tampil sebagai SATU kolom (mis. TANAH MATANG
     * 50%/20%/30% menjadi satu "TANAH MATANG") dan nominalnya dijumlah. Daftarnya berdiri di
     * SINI supaya controller, modal Vue, dan layar Master Data KPR membaca satu sumber; ditulis
     * tiga kali, menambah satu pilihan berarti mengingat tiga tempat dan yang terlewat hanya
     * akan menolak simpan tanpa menjelaskan kenapa.
     */
    public const KPR_SYNC = [
        'tanah_matang' => 'TANAH MATANG',
        'pondasi'      => 'PONDASI',
        'topping_off'  => 'TOPPING OFF',
        'bast_ajb'     => 'BAST & AJB',
        'dajam'        => 'DAJAM',
    ];

    // 'kpr_sync' WAJIB ada di sini: tanpa entri ini Eloquent membuangnya diam-diam pada
    // create()/update() — validasinya lulus, notifikasi "tersimpan" muncul, kolomnya NULL,
    // dan barisnya langsung tidak bisa disunting lagi.
    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'percentage', 'is_dajam', 'kpr_sync', 'keterangan', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_dajam'   => 'boolean',
        'percentage' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(BankRetentionTerm::class);
    }
}
