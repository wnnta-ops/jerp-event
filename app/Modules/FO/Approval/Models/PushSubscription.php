<?php

namespace App\Modules\FO\Approval\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Langganan Web Push satu perangkat app approver (PWA). Bukan data tenant — milik user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property bool $giliran
 * @property bool $ajuan
 * @property bool $harian
 * @property string $jam_harian
 * @property Carbon|null $harian_terakhir
 */
class PushSubscription extends Model
{
    protected $table = 'fo_push_subscriptions';

    /** Sama dengan default kolom di migrasi — supaya langganan baru langsung punya nilainya. */
    protected $attributes = [
        'giliran' => true,
        'ajuan' => true,
        'harian' => true,
        'jam_harian' => '08:00',
    ];

    protected $fillable = [
        'user_id', 'token_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'perangkat',
        'giliran', 'ajuan', 'harian', 'jam_harian', 'harian_terakhir', 'terkirim_at',
    ];

    protected $casts = [
        'giliran' => 'boolean',
        'ajuan' => 'boolean',
        'harian' => 'boolean',
        'harian_terakhir' => 'date',
        'terkirim_at' => 'datetime',
    ];

    /** Hapus langganan push yang terikat ke token login tertentu (perangkat dikeluarkan). */
    public static function hapusUntukToken(int|array $tokenId): void
    {
        static::query()->whereIn('token_id', (array) $tokenId)->delete();
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
