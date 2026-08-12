<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Models\User;
use App\Support\Barcode\QrCode;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barcode tanda tangan seorang pengguna — dipakai sebagai TTD pada SIP.
 */
class BsSignatureBarcode extends Model
{
    use HasTenant;

    protected $table = 'bs_signature_barcodes';

    protected $fillable = ['tenant_id', 'user_id', 'code', 'label', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /**
     * SVG QR Code siap ditempel ke halaman / dokumen cetak.
     *
     * @param  int  $moduleSize  ukuran 1 modul (px) — menentukan besar kotak QR
     */
    public function svg(int $moduleSize = 3, bool $showText = false): string
    {
        return QrCode::svg((string) $this->code, $moduleSize, 4, $showText);
    }

    /**
     * Peta user_id → SVG QR untuk sekumpulan pengguna, hanya yang barcode-nya aktif.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,string>
     */
    public static function svgMapFor(array $userIds, int $moduleSize = 3): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if (! $ids) {
            return [];
        }

        return self::query()->whereIn('user_id', $ids)->where('is_active', true)
            ->get(['user_id', 'code'])
            ->mapWithKeys(fn ($b) => [(int) $b->user_id => QrCode::svg((string) $b->code, $moduleSize)])
            ->all();
    }
}
