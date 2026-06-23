<?php

namespace App\Modules\FO\Booked\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Periode Setup Hold — rentang tanggal saat tombol Hold Unit aktif (mis. weekend
 * saat finance libur). Maks 2 hari langsung ACTIVE; >2 hari PENDING sampai Approval.
 */
class HoldPeriod extends Model
{
    protected $table = 'fo_hold_periods';

    protected $fillable = [
        'tenant_id', 'tgl_mulai', 'tgl_selesai', 'max_hari',
        'keterangan', 'status', 'approval_id', 'created_by',
    ];

    protected $casts = [
        'tgl_mulai' => 'date',
        'tgl_selesai' => 'date',
        'max_hari' => 'integer',
    ];

    /** Durasi periode dalam hari (inklusif). */
    public function getDurasiHariAttribute(): int
    {
        return (int) $this->tgl_mulai->diffInDays($this->tgl_selesai) + 1;
    }

    /** Periode ini sedang aktif & mencakup hari ini? */
    public function isActiveNow(): bool
    {
        return $this->status === 'ACTIVE'
            && now()->betweenIncluded($this->tgl_mulai->startOfDay(), $this->tgl_selesai->endOfDay());
    }
}
