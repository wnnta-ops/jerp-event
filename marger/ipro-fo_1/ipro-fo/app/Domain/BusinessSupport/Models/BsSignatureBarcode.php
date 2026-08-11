<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Models\User;
use App\Support\Barcode\Code128;
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

    /** SVG barcode siap ditempel ke halaman / dokumen cetak. */
    public function svg(int $moduleWidth = 2, int $height = 34, bool $showText = true): string
    {
        return Code128::svg((string) $this->code, $moduleWidth, $height, $showText);
    }

    /**
     * Peta user_id → SVG barcode untuk sekumpulan pengguna, hanya yang barcode-nya aktif.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,string>
     */
    public static function svgMapFor(array $userIds, int $moduleWidth = 2, int $height = 34): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if (! $ids) {
            return [];
        }

        return self::query()->whereIn('user_id', $ids)->where('is_active', true)
            ->get(['user_id', 'code'])
            ->mapWithKeys(fn ($b) => [(int) $b->user_id => Code128::svg((string) $b->code, $moduleWidth, $height)])
            ->all();
    }
}
