<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunjungan satu peserta (NUP) di satu pos.
 *
 * flags (json, daftar string):
 *   estimated    — ditutup otomatis karena NUP sudah scan Masuk di pos lain
 *   tanpa_masuk  — scan Keluar tanpa Masuk; started_at diambil dari pos sebelumnya
 *   tidak_urut   — Masuk di pos yang bukan pos berikutnya
 */
class EtVisit extends TenantAwareModel
{
    protected $table = 'et_visits';

    public const END_SCAN_KELUAR = 'scan_keluar';

    public const END_MANUAL = 'manual';

    public const END_NEXT_SCAN = 'next_scan';

    public const END_KOREKSI = 'koreksi';

    public const END_EVENT_CLOSED = 'event_closed';

    protected $fillable = [
        'tenant_id', 'participant_id', 'station_id', 'started_at', 'ended_at',
        'started_by', 'ended_by', 'end_reason', 'flags',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'flags' => 'array',
    ];

    /** @return BelongsTo<EtParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(EtParticipant::class, 'participant_id');
    }

    /** Petugas yang memulai layanan (scan Masuk). @return BelongsTo<\App\Models\User, $this> */
    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<EtStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(EtStation::class, 'station_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, (array) $this->flags, true);
    }

    public function addFlag(string $flag): void
    {
        $flags = (array) $this->flags;
        if (! in_array($flag, $flags, true)) {
            $flags[] = $flag;
        }
        $this->flags = $flags;
    }

    /** Lama layanan dalam detik, null bila belum lengkap. */
    public function serviceSeconds(): ?int
    {
        return ($this->started_at && $this->ended_at)
            ? (int) $this->started_at->diffInSeconds($this->ended_at)
            : null;
    }
}
