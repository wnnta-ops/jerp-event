<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Titik scan di sebuah pos: masuk (mulai dilayani), keluar (selesai dilayani),
 * atau single (satu HP — scan pertama = masuk, scan kedua = keluar).
 */
class EtScanPoint extends TenantAwareModel
{
    protected $table = 'et_scan_points';

    public const MASUK = 'masuk';

    public const KELUAR = 'keluar';

    public const SINGLE = 'single';

    protected $fillable = ['tenant_id', 'station_id', 'kind', 'label'];

    /** @return BelongsTo<EtStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(EtStation::class, 'station_id');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::MASUK => 'MASUK',
            self::KELUAR => 'KELUAR',
            default => 'SCAN',
        };
    }
}
