<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aktivasi NUP — flow hari-H launching di meja reception. Hanya NUP yang sudah
 * verif APPROVED + sudah punya nomor NUP yang boleh diaktivasi.
 *
 * State machine pada NupRegistration.status (existing enum):
 *   APPROVED (post-verif, belum hadir)  → status='DAFTAR' atau 'BAYAR'
 *   AKTIVASI (sudah check-in)           → status='CHECK_IN', tgl_check_in=now
 *   HOLD (ditahan reception)            → is_hold=true (status apa pun)
 *   PILIH_UNIT (sudah pilih unit)       → status='PILIH_UNIT'
 *   BATAL (cancel aktivasi)             → status revert + tgl_check_in=null
 */
class AktivasiNupService
{
    public function aktivasi(NupRegistration $reg): NupRegistration
    {
        $this->assertEligible($reg);

        if ($reg->status === 'CHECK_IN') {
            return $reg; // idempotent
        }

        if ($reg->is_hold) {
            throw new RuntimeException(
                'NUP '.$reg->no_nup.' sedang HOLD. Buka hold dulu sebelum aktivasi.'
            );
        }

        return DB::transaction(function () use ($reg) {
            $reg->update([
                'status' => 'CHECK_IN',
                'tgl_check_in' => now(),
            ]);

            return $reg->refresh();
        });
    }

    public function hold(NupRegistration $reg, ?string $reason = null): NupRegistration
    {
        $this->assertEligible($reg);

        if ($reg->status === 'PILIH_UNIT') {
            throw new RuntimeException('NUP '.$reg->no_nup.' sudah pilih unit, tidak bisa di-hold.');
        }

        $reg->update([
            'is_hold' => true,
            'hold_reason' => $reason,
        ]);

        return $reg->refresh();
    }

    public function unhold(NupRegistration $reg): NupRegistration
    {
        if (! $reg->is_hold) {
            return $reg;
        }

        $reg->update([
            'is_hold' => false,
            'hold_reason' => null,
        ]);

        return $reg->refresh();
    }

    public function cancelAktivasi(NupRegistration $reg, ?string $reason = null): NupRegistration
    {
        if ($reg->status === 'PILIH_UNIT') {
            throw new RuntimeException(
                'NUP '.$reg->no_nup.' sudah pilih unit. Batalkan reservasi unit dulu.'
            );
        }

        if ($reg->status !== 'CHECK_IN') {
            return $reg; // not activated → no-op
        }

        return DB::transaction(function () use ($reg, $reason) {
            $reg->update([
                'status' => 'DAFTAR',         // revert ke pre-aktivasi
                'tgl_check_in' => null,
                'keterangan' => trim(($reg->keterangan ? $reg->keterangan."\n" : '').
                    '[Aktivasi dibatalkan '.now()->format('d M Y H:i').' oleh '.(Auth::user()?->full_name ?? 'system').($reason ? ' — '.$reason : '').']'),
            ]);

            return $reg->refresh();
        });
    }

    private function assertEligible(NupRegistration $reg): void
    {
        if ($reg->verif_status !== NupRegistration::VERIF_APPROVED) {
            throw new RuntimeException(
                'NUP '.($reg->no_nup ?: 'tanpa nomor').' belum APPROVED ('.($reg->verif_status ?: 'kosong').').'
            );
        }

        if (empty($reg->no_nup)) {
            throw new RuntimeException('NUP belum punya nomor — proses approval belum tuntas.');
        }
    }
}
