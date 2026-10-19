<?php

namespace App\Modules\FO\Komunikasi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teks email + pilihan template WA untuk satu jenis pesan. lokasi_id NULL = berlaku untuk
 * semua cluster; baris ber-lokasi_id mengalahkannya untuk customer di cluster tersebut.
 */
class TemplatePesan extends TenantAwareModel
{
    protected $table = 'fo_template_pesan';

    protected $fillable = [
        'tenant_id', 'jenis', 'lokasi_id', 'kirim_email', 'email_subjek', 'email_isi', 'email_lampiran',
        'kirim_wa', 'wa_template_id', 'wa_params', 'is_active',
    ];

    protected $casts = [
        'kirim_email' => 'boolean',
        'email_lampiran' => 'boolean',
        'kirim_wa' => 'boolean',
        'is_active' => 'boolean',
        'wa_params' => 'array',
    ];

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    public function waTemplate(): BelongsTo
    {
        return $this->belongsTo(WaTemplate::class, 'wa_template_id');
    }

    /** Template yang berlaku: khusus cluster bila ada & aktif, selain itu "semua cluster". */
    public static function untuk(string $jenis, ?int $lokasiId = null): ?self
    {
        $q = static::query()->where('jenis', $jenis)->where('is_active', true)->with('waTemplate');

        if ($lokasiId) {
            $khusus = (clone $q)->where('lokasi_id', $lokasiId)->latest('id')->first();
            if ($khusus) {
                return $khusus;
            }
        }

        return $q->whereNull('lokasi_id')->latest('id')->first();
    }

    public function labelJenis(): string
    {
        return JenisPesan::label($this->jenis);
    }
}
