<?php

namespace App\Modules\FO\Komunikasi\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template WhatsApp yang ditempel dari dashboard Qontak. Kalimatnya dikelola di Qontak
 * (harus disetujui Meta); aplikasi hanya menyimpan ID template + jumlah parameternya.
 */
class WaTemplate extends TenantAwareModel
{
    protected $table = 'fo_wa_templates';

    protected $fillable = [
        'tenant_id', 'nama', 'template_id', 'contoh_isi', 'jumlah_param', 'pakai_dokumen', 'is_active', 'keterangan',
    ];

    protected $casts = [
        'jumlah_param' => 'integer',
        'pakai_dokumen' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function dipakaiDi(): HasMany
    {
        return $this->hasMany(TemplatePesan::class, 'wa_template_id');
    }

    /** Nomor {{n}} terbesar di contoh isi Qontak = jumlah parameter template. */
    public static function hitungParam(?string $contoh): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $contoh, $m);

        return $m[1] === [] ? 0 : (int) max(array_map('intval', $m[1]));
    }
}
