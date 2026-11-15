<?php

namespace App\Modules\Event\Services;

use App\Models\User;
use App\Modules\Event\Data\FoParticipantData;
use App\Modules\Event\Data\ScanResult;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use App\Modules\Event\Policies\EventPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu scan di satu titik scan (Masuk / Keluar / single).
 *
 * Urutan: akses → event open → cari NUP (FO, read-only) → pilih satu NUP →
 * transaksi dengan baris peserta dikunci → buka/tutup kunjungan. Setiap
 * percobaan dicatat di et_scan_logs, diterima maupun ditolak.
 *
 * Pelacakan per NUP: customer dengan dua NUP = dua peserta, dua antrean.
 */
class ScanService
{
    /** Scan ulang di titik yang sama untuk NUP yang sama dalam jendela ini = duplikat. */
    public const DUPLICATE_SECONDS = 5;

    /** Batas waktu "Batalkan scan terakhir". */
    public const UNDO_SECONDS = 30;

    public function __construct(
        private readonly FoNupLookup $fo,
        private readonly SyncCheckInService $sync,
        private readonly EventPolicy $policy,
    ) {}

    /**
     * @param  int|null  $nupRegistrationId  Pilihan dari picker "Customer ini punya 2 NUP".
     */
    public function handle(
        int $scanPointId,
        string $payload,
        User $user,
        ?int $nupRegistrationId = null,
        ?string $userAgent = null,
    ): ScanResult {
        $payload = mb_substr(trim($payload), 0, 255);

        $point = EtScanPoint::query()->with('station.event')->find($scanPointId);
        $event = $point?->station?->event;
        if (! $point || ! $event) {
            return new ScanResult(ScanResult::REJECTED, 'Akun tidak punya akses ke pos ini');
        }

        $log = $this->logger($event, $point, $user, $payload, $userAgent);

        $tolak = function (string $pesan, ?FoParticipantData $d = null) use ($log) {
            $log(EtScanLog::REJECTED, $pesan, $d?->nup_registration_id);

            return new ScanResult(ScanResult::REJECTED, $pesan, $d?->name, $d?->no_nup);
        };

        if (! $this->policy->canScanAt($user, $point)) {
            return $tolak('Akun tidak punya akses ke pos ini');
        }
        if (! $event->isOpen()) {
            return $tolak($event->status === EtEvent::CLOSED ? 'Event sudah ditutup' : 'Event belum dibuka');
        }
        if ($point->station->isFoCheckin()) {
            return $tolak('Pos 1 dicatat di FO (Aktivasi NUP), bukan di sini');
        }

        // ── Event mandiri: satu customer = satu peserta, tercatat saat scan pertama ──
        if ($event->isMandiri()) {
            $customer = $this->fo->findCustomerByScan($payload);
            if ($customer === null) {
                return $tolak($this->fo->explainCustomerMiss($payload));
            }
            $p = $this->sync->upsertCustomer($event, $customer);

            return $this->apply($event, $point, $this->effectiveKind($point, collect([$p])), $p, $user, $log);
        }

        // ── Cari NUP di FO (selalu, supaya hold / batal aktivasi langsung terasa) ──
        $kandidat = $this->fo->findByScan($payload, (int) $event->fo_nup_event_id);
        if ($nupRegistrationId !== null) {
            $kandidat = $kandidat->where('nup_registration_id', $nupRegistrationId)->values();
        }
        if ($kandidat->isEmpty()) {
            $alasan = $nupRegistrationId !== null
                ? ($this->fo->findByIds((int) $event->fo_nup_event_id, [$nupRegistrationId])->first()?->rejectionReason() ?? 'Kode tidak ditemukan')
                : ($this->fo->explainMiss($payload, (int) $event->fo_nup_event_id) ?? 'Kode tidak ditemukan');

            return $tolak($alasan);
        }

        /** @var Collection<int, EtParticipant> $peserta  key = fo_nup_registration_id */
        $peserta = $kandidat->mapWithKeys(fn (FoParticipantData $d) => [
            $d->nup_registration_id => $this->sync->upsert($event, $d)[0],
        ]);

        $kind = $this->effectiveKind($point, $peserta);
        $pilihan = $this->resolve($kind, $point, $peserta);

        if ($pilihan instanceof Collection) {
            $selesaiSemua = $pilihan->isEmpty();
            if ($selesaiSemua) {
                return $tolak($this->alasanTakAdaYangBisa($peserta), $kandidat->first());
            }

            $daftar = $pilihan->map(fn (EtParticipant $p) => [
                'nup_registration_id' => (int) $p->fo_nup_registration_id,
                'no_nup' => $p->no_nup,
                'name' => $p->name_snapshot,
            ])->values()->all();

            $log(EtScanLog::REJECTED, 'Perlu pilih NUP ('.count($daftar).' kandidat)');

            return new ScanResult(
                ScanResult::PICK,
                'Customer ini punya '.count($daftar).' NUP — pilih: '.collect($daftar)->map(fn ($c) => 'NUP '.$c['no_nup'])->implode(' / '),
                name: $pilihan->first()->name_snapshot,
                candidates: $daftar,
            );
        }

        return $this->apply($event, $point, $kind, $pilihan, $user, $log);
    }

    /**
     * Tombol "Selesai" di HP Keluar: tutup kunjungan yang sedang berjalan di pos
     * ini tanpa scan (end_reason manual). Aturan akses & status event sama
     * dengan scan; tercatat di log dan bisa dibatalkan seperti scan biasa.
     */
    public function selesaikanManual(int $scanPointId, int $participantId, User $user, ?string $userAgent = null): ScanResult
    {
        $point = EtScanPoint::query()->with('station.event')->find($scanPointId);
        $event = $point?->station?->event;
        $p = EtParticipant::query()->find($participantId);
        if (! $point || ! $event || ! $p || (int) $p->event_id !== (int) $event->id) {
            return new ScanResult(ScanResult::REJECTED, 'Peserta tidak ditemukan');
        }

        $log = $this->logger($event, $point, $user, 'manual:'.($p->no_nup ?? $p->id), $userAgent);
        $tolak = function (string $pesan) use ($log, $p) {
            $log(EtScanLog::REJECTED, $pesan, $p->fo_nup_registration_id, null, (int) $p->id);

            return new ScanResult(ScanResult::REJECTED, $pesan, $p->name_snapshot, $p->no_nup, $p->id);
        };

        if (! $this->policy->canScanAt($user, $point) || $point->kind === EtScanPoint::MASUK) {
            return $tolak('Akun tidak punya akses ke pos ini');
        }
        if (! $event->isOpen()) {
            return $tolak($event->status === EtEvent::CLOSED ? 'Event sudah ditutup' : 'Event belum dibuka');
        }
        if ($this->openVisitAt($p, $point->station_id) === null) {
            return $tolak('Tidak sedang dilayani di pos ini');
        }

        return $this->apply($event, $point, EtScanPoint::KELUAR, $p, $user, $log, EtVisit::END_MANUAL);
    }

    /**
     * Batalkan scan terakhir (≤ 30 detik, oleh user yang sama, belum ada scan
     * lain untuk NUP itu sesudahnya). Log asli tetap; ditambah baris 'undone'.
     */
    public function undo(int $logId, User $user): ScanResult
    {
        $asli = EtScanLog::query()->find($logId);
        if (! $asli || $asli->result !== EtScanLog::ACCEPTED || (int) $asli->user_id !== (int) $user->id) {
            return new ScanResult(ScanResult::REJECTED, 'Scan tidak bisa dibatalkan');
        }
        if ($asli->server_time->lt(now()->subSeconds(self::UNDO_SECONDS))) {
            return new ScanResult(ScanResult::REJECTED, 'Batas waktu pembatalan (30 detik) sudah lewat');
        }

        return DB::transaction(function () use ($asli, $user) {
            $effect = (array) $asli->effect;
            $p = EtParticipant::query()->lockForUpdate()->find($effect['participant_id'] ?? 0);
            if (! $p) {
                return new ScanResult(ScanResult::REJECTED, 'Scan tidak bisa dibatalkan');
            }

            $sudahDibatalkan = EtScanLog::query()->where('result', EtScanLog::UNDONE)
                ->where('effect->undo_of', $asli->id)->exists();
            $adaScanLanjutan = EtScanLog::query()->where('result', EtScanLog::ACCEPTED)
                ->where('event_id', $asli->event_id)
                ->where('participant_id', $asli->participant_id)
                ->where('id', '>', $asli->id)->exists();
            if ($sudahDibatalkan || $adaScanLanjutan) {
                return new ScanResult(ScanResult::REJECTED, 'Scan tidak bisa dibatalkan — sudah ada perubahan sesudahnya');
            }

            foreach ((array) ($effect['reopened_other'] ?? []) as $id) {
                $v = EtVisit::query()->find($id);
                $v?->forceFill(['ended_at' => null, 'ended_by' => null, 'end_reason' => null,
                    'flags' => array_values(array_diff((array) $v->flags, ['estimated']))])->save();
            }

            $visit = EtVisit::query()->find($effect['visit_id'] ?? 0);
            if ($visit) {
                match ($effect['action'] ?? null) {
                    'opened', 'created_closed' => $visit->delete(),
                    'closed' => $visit->forceFill(['ended_at' => null, 'ended_by' => null, 'end_reason' => null])->save(),
                    default => null,
                };
            }

            $p->forceFill((array) ($effect['participant_before'] ?? []))->save();

            $log = EtScanLog::create([
                'tenant_id' => $asli->tenant_id,
                'event_id' => $asli->event_id,
                'scan_point_id' => $asli->scan_point_id,
                'user_id' => $user->id,
                'raw_payload' => $asli->raw_payload,
                'fo_nup_registration_id' => $asli->fo_nup_registration_id,
                'participant_id' => $asli->participant_id,
                'result' => EtScanLog::UNDONE,
                'reason' => 'Scan #'.$asli->id.' dibatalkan',
                'server_time' => now(),
                'effect' => ['undo_of' => $asli->id],
            ]);

            return new ScanResult(ScanResult::ACCEPTED, 'Scan terakhir dibatalkan', $p->name_snapshot, $p->no_nup, $p->id, log_id: $log->id, action: 'undo');
        });
    }

    // ── internal ────────────────────────────────────────────────────────

    /**
     * Penulis log scan untuk satu titik. Satu baris per percobaan.
     *
     * @return \Closure(string, ?string=, ?int=, ?array=, ?int=): EtScanLog
     */
    private function logger(EtEvent $event, EtScanPoint $point, User $user, string $payload, ?string $userAgent): \Closure
    {
        return fn (string $result, ?string $reason = null, ?int $regId = null, ?array $effect = null, ?int $participantId = null) => EtScanLog::create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'scan_point_id' => $point->id,
            'user_id' => $user->id,
            'raw_payload' => $payload === '' ? '-' : $payload,
            'fo_nup_registration_id' => $regId,
            'participant_id' => $participantId,
            'result' => $result,
            'reason' => $reason,
            'server_time' => now(),
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'effect' => $effect,
        ]);
    }

    /**
     * Titik single: scan pertama = masuk, scan kedua = keluar. Untuk satu NUP
     * yang punya kunjungan terbuka di pos ini, single bertindak sebagai keluar.
     *
     * @param  Collection<int, EtParticipant>  $peserta
     */
    private function effectiveKind(EtScanPoint $point, Collection $peserta): string
    {
        if ($point->kind !== EtScanPoint::SINGLE) {
            return $point->kind;
        }

        $terbuka = $peserta->filter(fn (EtParticipant $p) => $this->openVisitAt($p, $point->station_id) !== null);

        return $terbuka->count() === 1 ? EtScanPoint::KELUAR : EtScanPoint::MASUK;
    }

    /**
     * Pilih satu NUP dari kandidat. Kembalikan Collection bila perlu picker
     * (kosong = tak ada yang bisa diproses).
     *
     * @param  Collection<int, EtParticipant>  $peserta
     * @return EtParticipant|Collection<int, EtParticipant>
     */
    private function resolve(string $kind, EtScanPoint $point, Collection $peserta): EtParticipant|Collection
    {
        if ($peserta->count() === 1) {
            return $peserta->first();
        }

        $masihJalan = $peserta->filter(fn (EtParticipant $p) => $p->status === EtParticipant::ACTIVE);

        if ($kind === EtScanPoint::KELUAR) {
            $terbuka = $masihJalan->filter(fn (EtParticipant $p) => $this->openVisitAt($p, $point->station_id) !== null);
            if ($terbuka->count() === 1) {
                return $terbuka->first();
            }

            return $masihJalan->count() === 1 ? $masihJalan->first() : $masihJalan->values();
        }

        $siap = $masihJalan->filter(fn (EtParticipant $p) => $p->current_state !== EtParticipant::IN_SERVICE);
        if ($siap->count() === 1) {
            return $siap->first();
        }

        return ($siap->isEmpty() ? $masihJalan : $siap)->values();
    }

    /** @param Collection<int, EtParticipant> $peserta */
    private function alasanTakAdaYangBisa(Collection $peserta): string
    {
        if ($peserta->every(fn (EtParticipant $p) => $p->status === EtParticipant::FINISHED)) {
            return 'Sudah selesai';
        }
        if ($peserta->contains(fn (EtParticipant $p) => $p->status === EtParticipant::LEFT)) {
            return 'Peserta sudah ditandai pulang';
        }

        return 'Belum aktivasi di pos 1';
    }

    /**
     * @param  \Closure(string, ?string=, ?int=, ?array=, ?int=): EtScanLog  $log
     */
    private function apply(EtEvent $event, EtScanPoint $point, string $kind, EtParticipant $pilih, User $user, \Closure $log, string $endReason = EtVisit::END_SCAN_KELUAR): ScanResult
    {
        return DB::transaction(function () use ($event, $point, $kind, $pilih, $user, $log, $endReason) {
            /** @var EtParticipant $p */
            $p = EtParticipant::query()->lockForUpdate()->findOrFail($pilih->id);
            $regId = $p->fo_nup_registration_id;
            $pid = (int) $p->id;
            $station = $point->station;
            $now = now();

            $duplikat = EtScanLog::query()
                ->where('scan_point_id', $point->id)
                ->where('participant_id', $pid)
                ->where('result', EtScanLog::ACCEPTED)
                ->where('server_time', '>=', $now->copy()->subSeconds(self::DUPLICATE_SECONDS))
                ->exists();
            if ($duplikat) {
                $log(EtScanLog::DUPLICATE, 'Scan ganda (< '.self::DUPLICATE_SECONDS.' detik)', $regId, null, $pid);

                return new ScanResult(ScanResult::DUPLICATE, 'Sudah tercatat barusan', $p->name_snapshot, $p->no_nup, $p->id);
            }

            if ($p->status === EtParticipant::FINISHED) {
                $log(EtScanLog::REJECTED, 'Sudah selesai', $regId, null, $pid);

                return new ScanResult(ScanResult::REJECTED, 'Sudah selesai', $p->name_snapshot, $p->no_nup, $p->id);
            }
            if ($p->status !== EtParticipant::ACTIVE) {
                $pesan = $p->status === EtParticipant::LEFT ? 'Peserta sudah ditandai pulang' : 'Belum aktivasi di pos 1';
                $log(EtScanLog::REJECTED, $pesan, $regId, null, $pid);

                return new ScanResult(ScanResult::REJECTED, $pesan, $p->name_snapshot, $p->no_nup, $p->id);
            }

            $sebelum = $p->only(['status', 'current_station_id', 'current_state', 'finished_at']);
            $sebelum['finished_at'] = $p->finished_at?->toDateTimeString();
            $stations = $event->stations()->get();

            [$aksi, $visit, $ditutupLain, $warning] = $kind === EtScanPoint::MASUK
                ? $this->masuk($p, $station, $stations, $user, $now)
                : $this->keluar($p, $station, $stations, $user, $now, $endReason);

            if ($aksi === 'already_in') {
                $log(EtScanLog::DUPLICATE, 'Sudah masuk di pos ini', $regId, null, $pid);

                return new ScanResult(ScanResult::DUPLICATE, 'Sudah masuk di pos ini sejak '.$visit->started_at->format('H:i'), $p->name_snapshot, $p->no_nup, $p->id);
            }

            $entry = $log(EtScanLog::ACCEPTED, $endReason === EtVisit::END_MANUAL ? trim('Selesai manual. '.$warning) : $warning, $regId, [
                'participant_id' => $p->id,
                'visit_id' => $visit->id,
                'action' => $aksi,
                'reopened_other' => $ditutupLain,
                'participant_before' => $sebelum,
            ], $pid);

            $pesan = match (true) {
                $kind === EtScanPoint::MASUK => 'Masuk '.$station->name,
                $station->isFinish() => 'Selesai — '.$station->name.' (event tuntas)',
                default => 'Keluar '.$station->name,
            };

            return new ScanResult(ScanResult::ACCEPTED, $pesan, $p->name_snapshot, $p->no_nup, $p->id,
                warning: $warning, log_id: $entry->id, action: $kind);
        });
    }

    /**
     * @param  Collection<int, EtStation>  $stations
     * @return array{0:string, 1:EtVisit, 2:list<int>, 3:?string}
     */
    private function masuk(EtParticipant $p, EtStation $station, Collection $stations, User $user, \DateTimeInterface $now): array
    {
        $sudah = $this->openVisitAt($p, $station->id);
        if ($sudah) {
            return ['already_in', $sudah, [], null];
        }

        $ditutup = $this->closeOpenElsewhere($p, $station->id, $user, $now);

        $warning = null;
        $flags = [];
        $diharapkan = $this->expectedStation($p, $stations);
        if ($diharapkan && $diharapkan->id !== $station->id) {
            $warning = 'Urutan pos tidak sesuai — seharusnya ke '.$diharapkan->name;
            $flags[] = 'tidak_urut';
        }

        $visit = EtVisit::create([
            'tenant_id' => $p->tenant_id,
            'participant_id' => $p->id,
            'station_id' => $station->id,
            'started_at' => $now,
            'started_by' => $user->id,
            'flags' => $flags ?: null,
        ]);

        $p->forceFill(['current_station_id' => $station->id, 'current_state' => EtParticipant::IN_SERVICE])->save();

        return ['opened', $visit, $ditutup, $warning];
    }

    /**
     * @param  Collection<int, EtStation>  $stations
     * @return array{0:string, 1:EtVisit, 2:list<int>, 3:?string}
     */
    private function keluar(EtParticipant $p, EtStation $station, Collection $stations, User $user, \DateTimeInterface $now, string $endReason = EtVisit::END_SCAN_KELUAR): array
    {
        $visit = $this->openVisitAt($p, $station->id);
        $ditutup = $this->closeOpenElsewhere($p, $station->id, $user, $now);
        $warning = null;

        if ($visit) {
            $visit->forceFill(['ended_at' => $now, 'ended_by' => $user->id, 'end_reason' => $endReason])->save();
            $aksi = 'closed';
        } else {
            $visit = EtVisit::create([
                'tenant_id' => $p->tenant_id,
                'participant_id' => $p->id,
                'station_id' => $station->id,
                'started_at' => $this->previousEnd($p, $station, $stations) ?? $now,
                'ended_at' => $now,
                'started_by' => null,
                'ended_by' => $user->id,
                'end_reason' => EtVisit::END_SCAN_KELUAR,
                'flags' => ['tanpa_masuk'],
            ]);
            $aksi = 'created_closed';
            $warning = 'Keluar tanpa scan Masuk — waktu mulai diperkirakan';
        }

        if ($station->isFinish()) {
            $p->forceFill([
                'status' => EtParticipant::FINISHED,
                'finished_at' => $now,
                'current_station_id' => $station->id,
                'current_state' => EtParticipant::DONE,
            ])->save();
        } else {
            $berikut = $stations->first(fn (EtStation $s) => $s->sequence > $station->sequence);
            $p->forceFill([
                'current_station_id' => $berikut?->id ?? $station->id,
                'current_state' => $berikut ? EtParticipant::WAITING : EtParticipant::DONE,
            ])->save();
        }

        return [$aksi, $visit, $ditutup, $warning];
    }

    /**
     * NUP yang sama masih punya kunjungan terbuka di pos lain → tutup sekarang
     * dengan alasan next_scan + flag estimated.
     *
     * @return list<int> id kunjungan yang ditutup
     */
    private function closeOpenElsewhere(EtParticipant $p, int $stationId, User $user, \DateTimeInterface $now): array
    {
        $ids = [];
        EtVisit::query()
            ->where('participant_id', $p->id)
            ->where('station_id', '!=', $stationId)
            ->whereNull('ended_at')
            ->get()
            ->each(function (EtVisit $v) use (&$ids, $user, $now) {
                $v->addFlag('estimated');
                $v->forceFill(['ended_at' => $now, 'ended_by' => $user->id, 'end_reason' => EtVisit::END_NEXT_SCAN])->save();
                $ids[] = $v->id;
            });

        return $ids;
    }

    private function openVisitAt(EtParticipant $p, int $stationId): ?EtVisit
    {
        return EtVisit::query()
            ->where('participant_id', $p->id)
            ->where('station_id', $stationId)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
    }

    /**
     * Pos yang seharusnya dimasuki berikutnya: pos sesudah pos terakhir yang
     * selesai dikunjungi (atau pos 2 bila belum ada).
     *
     * @param  Collection<int, EtStation>  $stations
     */
    private function expectedStation(EtParticipant $p, Collection $stations): ?EtStation
    {
        $selesai = EtVisit::query()->where('participant_id', $p->id)->whereNotNull('ended_at')->pluck('station_id');
        $seqTerakhir = $stations->whereIn('id', $selesai)->max('sequence')
            ?? $stations->firstWhere('type', EtStation::FO_CHECKIN)?->sequence
            ?? 0;

        return $stations->first(fn (EtStation $s) => $s->sequence > $seqTerakhir && ! $s->isFoCheckin());
    }

    /**
     * Akhir layanan di pos sebelumnya (untuk Keluar tanpa Masuk). Pos 2 → tgl_check_in FO.
     *
     * @param  Collection<int, EtStation>  $stations
     */
    private function previousEnd(EtParticipant $p, EtStation $station, Collection $stations): ?\DateTimeInterface
    {
        $sebelumnya = $stations->filter(fn (EtStation $s) => $s->sequence < $station->sequence && ! $s->isFoCheckin())->pluck('id');

        $akhir = $sebelumnya->isEmpty() ? null : EtVisit::query()
            ->where('participant_id', $p->id)
            ->whereIn('station_id', $sebelumnya)
            ->whereNotNull('ended_at')
            ->max('ended_at');

        return $akhir ? Carbon::parse($akhir) : $p->checked_in_at;
    }
}
