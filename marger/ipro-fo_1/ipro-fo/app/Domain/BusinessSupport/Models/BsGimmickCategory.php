<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori Gimmick: memetakan kategori → akun debit + akun kredit.
 * Fungsi identik dengan BsApCategory (Kategori Invoice).
 *
 * Sejak multi-akun: daftar akun sebenarnya ada di bs_gimmick_category_accounts (boleh lebih
 * dari satu per sisi, masing-masing berporsi %). Kolom debit_account_id & payable_account_id
 * tetap dipelihara sebagai akun UTAMA (baris pertama tiap sisi) agar pembaca lama tetap benar.
 *
 * kpr_biaya_key menautkan kategori ke kolom Rincian Biaya pada Master Data KPR, sehingga biaya
 * yang sudah diposting di BO otomatis muncul di rekap KPR.
 */
class BsGimmickCategory extends Model
{
    use HasTenant;

    /**
     * Kolom Rincian Biaya pada Master Data KPR — urut sesuai tampilan tabelnya.
     * Dipakai bersama oleh MasterDataKprController & setup Kategori Gimmick agar tidak ada
     * kolom yang terlewat saat kategori dipetakan.
     */
    public const KPR_BIAYA_COLUMNS = [
        'kpr'   => 'BIAYA KPR',
        'ht'    => 'BIAYA HT',
        'ppjb'  => 'BIAYA PPJB',
        'ssp'   => 'BIAYA SSP',
        'bphtb' => 'BIAYA BPHTB',
        'ajb'   => 'BIAYA AJB',
    ];

    protected $table = 'bs_gimmick_categories';
    protected $fillable = ['tenant_id', 'cluster_id', 'code', 'name', 'kpr_biaya_key', 'sync_kpr', 'debit_account_id', 'payable_account_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sync_kpr' => 'boolean'];

    public function debitAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'debit_account_id'); }
    public function payableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'payable_account_id'); }

    /**
     * Cluster tempat kategori ini berlaku. KOSONG berarti berlaku untuk semua cluster —
     * kategori semacam itu dipakai sebagai cadangan ketika sebuah cluster belum punya
     * kategori sendiri, sehingga menambah cluster tidak pernah membuat gimmick yang sudah
     * berjalan kehilangan akunnya.
     */
    public function cluster(): BelongsTo { return $this->belongsTo(Cluster::class, 'cluster_id'); }

    /** Seluruh akun kategori ini (debit & kredit), urut tampil. */
    public function accounts(): HasMany
    {
        return $this->hasMany(BsGimmickCategoryAccount::class, 'gimmick_category_id')
            ->orderBy('side')->orderBy('sort_order')->orderBy('id');
    }
}
