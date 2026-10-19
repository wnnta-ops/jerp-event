<?php

namespace App\Modules\FO\Komunikasi\Models;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu email/WA yang dikirim (atau gagal/dilewati) oleh aplikasi — sumber Monitoring Pesan. */
class LogPesan extends Model
{
    public const TERKIRIM = 'TERKIRIM';

    public const GAGAL = 'GAGAL';

    public const DILEWATI = 'DILEWATI';

    protected $table = 'fo_log_pesan';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'percobaan' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (self $m) {
            if (empty($m->tenant_id)) {
                $m->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class, 'kontrak_id');
    }

    /** Sudah pernah TERKIRIM untuk dokumen (& kanal) yang sama? Mencegah kiriman ganda. */
    public static function sudahTerkirim(string $jenis, string $refType, int $refId, ?string $channel = null): bool
    {
        return static::query()->where('jenis', $jenis)->where('ref_type', $refType)->where('ref_id', $refId)
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->where('status', self::TERKIRIM)->exists();
    }
}
