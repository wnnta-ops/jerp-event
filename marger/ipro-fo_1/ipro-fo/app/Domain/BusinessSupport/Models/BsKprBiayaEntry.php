<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu biaya yang SUDAH terbit invoice-nya untuk sebuah SPR/booking, dipetakan ke salah satu
 * kolom Rincian Biaya di Master Data KPR (lihat BsGimmickCategory::KPR_BIAYA_COLUMNS).
 *
 * Ditulis otomatis saat Posting Invoice dari FO menerbitkan invoice hutang dari antrian GMC,
 * sehingga rekap Master Data KPR ikut terisi tanpa input ulang.
 */
class BsKprBiayaEntry extends Model
{
    use HasTenant;

    protected $table = 'bs_kpr_biaya_entries';

    protected $fillable = [
        'tenant_id', 'booking_id', 'biaya_key', 'tgl', 'nominal',
        'source_type', 'source_ref', 'source_id', 'ap_invoice_id', 'gimmick_category_id', 'description',
    ];

    protected $casts = ['tgl' => 'date', 'nominal' => 'decimal:2'];
}
