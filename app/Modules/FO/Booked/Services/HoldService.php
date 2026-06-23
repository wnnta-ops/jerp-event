<?php

namespace App\Modules\FO\Booked\Services;

use App\Modules\FO\Booked\Models\HoldPeriod;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hold Unit sementara (Booked). Sales hold unit selama periode Setup Hold aktif
 * (mis. weekend finance libur). Bila finance memasukkan transfer cocok dalam masa
 * hold → lanjut booked (TTS+SPRS). Bila tidak → auto-release ke TERSEDIA.
 */
class HoldService
{
    /** Periode Setup Hold yang sedang aktif & mencakup hari ini (atau null). */
    public function activePeriod(): ?HoldPeriod
    {
        return HoldPeriod::query()
            ->where('status', 'ACTIVE')
            ->whereDate('tgl_mulai', '<=', now()->toDateString())
            ->whereDate('tgl_selesai', '>=', now()->toDateString())
            ->orderByDesc('tgl_selesai')
            ->first();
    }

    public function isHoldActive(): bool
    {
        return $this->activePeriod() !== null;
    }

    /**
     * Hold satu unit untuk registrasi booked sampai akhir periode hold aktif.
     * Unit harus TERSEDIA. hold_until = akhir hari tgl_selesai periode.
     */
    public function holdUnit(int $registrationId, Unit $unit, ?int $userId = null): Unit
    {
        $period = $this->activePeriod();
        if (! $period) {
            throw new RuntimeException('Tidak ada periode Setup Hold yang aktif hari ini.');
        }
        if ($unit->status !== 'TERSEDIA') {
            throw new RuntimeException('Unit tidak TERSEDIA (status: '.$unit->status.').');
        }

        return DB::transaction(function () use ($unit, $registrationId, $userId, $period) {
            $unit->update([
                'status' => 'HOLD',
                'hold_until' => $period->tgl_selesai->copy()->endOfDay(),
                'hold_registration_id' => $registrationId,
                'hold_by' => $userId,
                'hold_period_id' => $period->id,
            ]);

            return $unit->refresh();
        });
    }

    /** Lepas hold satu unit → kembali TERSEDIA + bersihkan field hold. */
    public function releaseUnit(Unit $unit): void
    {
        $unit->update([
            'status' => 'TERSEDIA',
            'hold_until' => null,
            'hold_registration_id' => null,
            'hold_by' => null,
            'hold_period_id' => null,
        ]);
    }

    /**
     * Auto-release: unit HOLD yang periode Setup Hold-nya sudah lewat (hari ini >
     * tgl_selesai periode) atau periode tak lagi ACTIVE → kembali TERSEDIA.
     * Mengikuti TANGGAL YANG DI-SETUP (bukan snapshot). Mengembalikan jumlah dilepas.
     */
    /**
     * Tandai periode Setup Hold yang sudah lewat jadwal (tgl_selesai < hari ini)
     * dari ACTIVE → SELESAI (otomatis non-aktif). Mengembalikan jumlah periode.
     */
    public function expirePeriods(): int
    {
        return HoldPeriod::query()->where('status', 'ACTIVE')
            ->whereDate('tgl_selesai', '<', now()->toDateString())
            ->update(['status' => 'SELESAI']);
    }

    public function releaseExpired(): int
    {
        $this->expirePeriods();
        $today = now()->startOfDay();
        $count = 0;
        foreach (Unit::query()->where('status', 'HOLD')->get() as $unit) {
            $period = $unit->hold_period_id ? HoldPeriod::find($unit->hold_period_id) : null;
            $stillHeld = $period
                ? ($period->status === 'ACTIVE' && ! $today->gt($period->tgl_selesai->copy()->startOfDay()))
                // Legacy (tanpa periode): pakai snapshot hold_until.
                : ($unit->hold_until && ! $unit->hold_until->isPast());
            if (! $stillHeld) {
                $this->releaseUnit($unit);
                $count++;
            }
        }

        return $count;
    }
}
