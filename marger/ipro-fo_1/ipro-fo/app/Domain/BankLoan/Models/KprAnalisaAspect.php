<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kategori Analisa Berkas — konfigurasi aspek scoring tahap "Analisa BI Checking & Berkas".
 * options: [{level:high|medium|low, desc, hint, score:int, bank:[BTN,BSN,BRI,BNI,MANDIRI]}]
 */
class KprAnalisaAspect extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'kpr_analisa_aspects';

    protected $fillable = ['tenant_id', 'aspect_key', 'aspect_no', 'label', 'scope', 'is_active', 'options'];

    protected $casts = [
        'aspect_no' => 'integer',
        'is_active' => 'boolean',
        'options'   => 'array',
    ];

    public const SCOPES = ['all' => 'Umum (semua pemohon)', 'karyawan' => 'Karyawan / Fixed Income', 'wirausaha' => 'Wirausaha / Non-Fixed'];

    public const LEVELS = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    /** Urutan bank pada matriks skor rekomendasi (indeks kolom bank[]). */
    public const BANKS = ['BTN', 'BSN', 'BRI', 'BNI', 'MANDIRI'];
}
