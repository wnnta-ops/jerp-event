<?php

namespace App\Modules\Event\Services;

use App\Models\User;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Policies\EventPolicy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Koreksi dari live board — untuk scan yang terlewat atau salah. Setiap koreksi
 * WAJIB beralasan, kunjungan yang tersentuh diberi flag 'koreksi', dan dicatat di
 * et_scan_logs (scan_point_id kosong, alasan di kolom reason).
 *
 * Boleh: superadmin, atau akun dengan can_live_board di event itu.
 */
class KoreksiService
{
    public function __construct(private readonly EventPolicy $policy) {}

    /** Tutup paksa kunjungan yang masih berjalan (lupa scan Keluar). */
    public function tutupKunjungan(EtVisit $visit, User $user, string $alasan): void
    {
        $this->jalankan($visit->participant, $user, $alasan, 'Tutup kunjungan', function (EtParticipant $p) use ($visit, $user) {
            $v = EtVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($v->ended_at !== null) {
                throw new RuntimeException('Kunjungan ini sudah selesai.');
            }
            $this->tutup($v, $user);
            $this->majukan($p, $v->station);
        });
    }

    /** Mulai layanan di pos ini (lupa scan Masuk). Kunjungan lain yang terbuka ditutup. */
    public function mulaiLayanan(EtParticipant $participant, EtStation $station, User $user, string $alasan): void
    {
        $this->jalankan($participant, $user, $alasan, 'Mulai layanan di '.$station->name, function (EtParticipant $p) use ($station, $user) {
            if ((int) $station->event_id !== (int) $p->event_id || $station->isFoCheckin()) {
                throw new RuntimeException('Pos tidak valid untuk peserta ini.');
            }
            $this->tutupSemuaTerbuka($p, $user);
            EtVisit::create([
                'tenant_id' => $p->tenant_id,
                'participant_id' => $p->id,
                'station_id' => $station->id,
                'started_at' => now(),
                'started_by' => $user->id,
                'flags' => ['koreksi'],
            ]);
            $p->forceFill([
                'status' => EtParticipant::ACTIVE,
                'finished_at' => null,
                'current_station_id' => $station->id,
                'current_state' => EtParticipant::IN_SERVICE,
            ])->save();
        });
    }

    /** Tandai peserta pulang / tidak melanjutkan. */
    public function tandaiPulang(EtParticipant $participant, User $user, string $alasan): void
    {
        $this->jalankan($participant, $user, $alasan, 'Tandai pulang', function (EtParticipant $p) use ($user) {
            $this->tutupSemuaTerbuka($p, $user);
            $p->forceFill(['status' => EtParticipant::LEFT, 'current_state' => EtParticipant::DONE])->save();
        });
    }

    /** Aktifkan kembali peserta yang sudah selesai / pulang — menunggu di pos berikutnya. */
    public function bukaKembali(EtParticipant $participant, User $user, string $alasan): void
    {
        $this->jalankan($participant, $user, $alasan, 'Buka kembali', function (EtParticipant $p) {
            if ($p->status === EtParticipant::ACTIVE) {
                throw new RuntimeException('Peserta masih aktif.');
            }
            if ($p->status === EtParticipant::CANCELLED) {
                throw new RuntimeException('Aktivasi NUP-nya dibatalkan di FO — aktivasi ulang di FO.');
            }

            $stations = EtStation::query()->where('event_id', $p->event_id)->where('type', '!=', EtStation::FO_CHECKIN)->orderBy('sequence')->get();
            $selesai = EtVisit::query()->where('participant_id', $p->id)->whereNotNull('ended_at')->pluck('station_id');
            $seq = $stations->whereIn('id', $selesai)->max('sequence') ?? 0;
            // Sudah sampai pos terakhir → kembali ke pos terakhir; selain itu ke pos berikutnya.
            $tuju = $stations->first(fn (EtStation $s) => $s->sequence > $seq) ?? $stations->last();

            $p->forceFill([
                'status' => EtParticipant::ACTIVE,
                'finished_at' => null,
                'current_station_id' => $tuju?->id,
                'current_state' => EtParticipant::WAITING,
            ])->save();
        });
    }

    // ── internal ────────────────────────────────────────────────────────

    /**
     * @param  \Closure(EtParticipant): void  $aksi
     */
    private function jalankan(?EtParticipant $participant, User $user, string $alasan, string $judul, \Closure $aksi): void
    {
        $alasan = trim($alasan);
        if ($participant === null) {
            throw new RuntimeException('Peserta tidak ditemukan.');
        }
        if (mb_strlen($alasan) < 3) {
            throw new RuntimeException('Alasan koreksi wajib diisi.');
        }
        /** @var EtEvent $event */
        $event = EtEvent::query()->findOrFail($participant->event_id);
        if (! $this->policy->canCorrect($user, $event)) {
            throw new RuntimeException('Akun tidak punya hak koreksi di event ini.');
        }

        DB::transaction(function () use ($participant, $user, $alasan, $judul, $aksi, $event) {
            $p = EtParticipant::query()->lockForUpdate()->findOrFail($participant->id);
            $aksi($p);

            EtScanLog::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'scan_point_id' => null,
                'participant_id' => $p->id,
                'user_id' => $user->id,
                'raw_payload' => 'koreksi',
                'fo_nup_registration_id' => $p->fo_nup_registration_id,
                'result' => EtScanLog::ACCEPTED,
                'reason' => mb_substr('Koreksi: '.$judul.' — '.$alasan, 0, 255),
                'server_time' => now(),
            ]);
        });
    }

    private function tutup(EtVisit $v, User $user): void
    {
        $v->addFlag('koreksi');
        $v->forceFill(['ended_at' => now(), 'ended_by' => $user->id, 'end_reason' => EtVisit::END_KOREKSI])->save();
    }

    private function tutupSemuaTerbuka(EtParticipant $p, User $user): void
    {
        EtVisit::query()->where('participant_id', $p->id)->whereNull('ended_at')->get()
            ->each(fn (EtVisit $v) => $this->tutup($v, $user));
    }

    /** Setelah kunjungan di $station ditutup: pos finish → selesai; selain itu menunggu di pos berikutnya. */
    private function majukan(EtParticipant $p, EtStation $station): void
    {
        if ($station->isFinish()) {
            $p->forceFill(['status' => EtParticipant::FINISHED, 'finished_at' => now(),
                'current_station_id' => $station->id, 'current_state' => EtParticipant::DONE])->save();

            return;
        }

        $berikut = EtStation::query()->where('event_id', $station->event_id)
            ->where('sequence', '>', $station->sequence)->orderBy('sequence')->first();
        $p->forceFill([
            'current_station_id' => $berikut?->id ?? $station->id,
            'current_state' => $berikut ? EtParticipant::WAITING : EtParticipant::DONE,
        ])->save();
    }
}
