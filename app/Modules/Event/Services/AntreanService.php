<?php

namespace App\Modules\Event\Services;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use Illuminate\Support\Carbon;

/**
 * Siapa menunggu dan siapa sedang dilayani di sebuah pos — untuk daftar di HP
 * scanner (dan live board). Hanya baca, satu baris per NUP.
 */
class AntreanService
{
    /**
     * Peserta yang menunggu masuk pos ini, paling lama menunggu di atas.
     * "Menunggu sejak" = keluar dari pos terakhir, atau check-in FO bila belum ke mana pun.
     *
     * $lewatOpsional: ikut tampilkan yang menunggu di pos opsional tepat sebelumnya
     * (untuk HP scanner). Live board mematikannya supaya tiap peserta muncul sekali.
     *
     * @return list<array{participant_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string}>
     */
    public function menunggu(EtStation $station, bool $lewatOpsional = true): array
    {
        $peserta = EtParticipant::query()
            ->where('event_id', $station->event_id)
            ->where('status', EtParticipant::ACTIVE)
            ->whereIn('current_station_id', $lewatOpsional ? $this->stasiunMenuju($station) : [(int) $station->id])
            ->where('current_state', EtParticipant::WAITING)
            ->get(['id', 'no_nup', 'name_snapshot', 'customer_code', 'checked_in_at']);

        $akhir = EtVisit::query()
            ->whereIn('participant_id', $peserta->pluck('id'))
            ->whereNotNull('ended_at')
            ->groupBy('participant_id')
            ->selectRaw('participant_id, max(ended_at) as terakhir')
            ->pluck('terakhir', 'participant_id');

        return $peserta->toBase()->map(function (EtParticipant $p) use ($akhir) {
            $since = isset($akhir[$p->id]) ? Carbon::parse($akhir[$p->id]) : $p->checked_in_at;

            return [
                'participant_id' => (int) $p->id,
                'no_nup' => $p->no_nup,
                'name' => $p->name_snapshot,
                'customer_code' => $p->customer_code,
                'since' => $since?->toIso8601String(),
            ];
        })->sortBy([['since', 'asc'], ['participant_id', 'asc']])->values()->all();
    }

    /**
     * Pos ini + pos OPSIONAL tepat sebelumnya (berderet): peserta yang menunggu di
     * pos opsional bisa saja melompat langsung ke pos ini.
     *
     * @return list<int>
     */
    private function stasiunMenuju(EtStation $station): array
    {
        $sebelum = EtStation::query()
            ->where('event_id', $station->event_id)
            ->where('sequence', '<', $station->sequence)
            ->orderByDesc('sequence')
            ->get(['id', 'is_optional', 'type']);

        $ids = [(int) $station->id];
        foreach ($sebelum as $s) {
            if (! $s->is_optional || $s->isFoCheckin()) {
                break;
            }
            $ids[] = (int) $s->id;
        }

        return $ids;
    }

    /**
     * Kunjungan yang sedang berjalan di pos ini, paling lama di atas.
     *
     * @return list<array{participant_id:int, visit_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string, flags:list<string>, started_by:?int, petugas:?string}>
     */
    public function dilayani(EtStation $station): array
    {
        return EtVisit::query()
            ->with(['participant:id,no_nup,name_snapshot,customer_code', 'petugas:id,name,full_name'])
            ->where('station_id', $station->id)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get()
            ->toBase()
            ->filter(fn (EtVisit $v) => $v->participant !== null)
            ->map(fn (EtVisit $v) => [
                'participant_id' => (int) $v->participant_id,
                'visit_id' => (int) $v->id,
                'no_nup' => $v->participant->no_nup,
                'name' => $v->participant->name_snapshot,
                'customer_code' => $v->participant->customer_code,
                'since' => $v->started_at?->toIso8601String(),
                'flags' => array_values(array_filter((array) $v->flags, 'is_string')),
                'started_by' => $v->started_by ? (int) $v->started_by : null,
                'petugas' => $v->petugas ? ($v->petugas->full_name ?: $v->petugas->name) : null,
            ])->values()->all();
    }

    /**
     * Data live board satu event: satu kolom per pos (tanpa pos FO), masing-masing
     * berisi yang menunggu & yang sedang dilayani, plus ringkasan status peserta.
     *
     * @return array{
     *     pos: list<array{id:int, code:string, name:string, sequence:int, target:?int, optional:bool, finish:bool,
     *         menunggu: list<array{participant_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string}>,
     *         dilayani: list<array{participant_id:int, visit_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string, flags:list<string>, started_by:?int, petugas:?string}>,
     *         selesai:int}>,
     *     ringkasan: array{total:int, aktif:int, selesai:int, pulang:int, batal:int}
     * }
     */
    public function papan(EtEvent $event): array
    {
        $stations = $event->stations()->where('type', '!=', EtStation::FO_CHECKIN)->get();

        $selesaiPerPos = EtVisit::query()
            ->whereIn('station_id', $stations->pluck('id'))
            ->whereNotNull('ended_at')
            ->groupBy('station_id')
            ->selectRaw('station_id, count(*) as n')
            ->pluck('n', 'station_id');

        $pos = $stations->toBase()->map(fn (EtStation $s) => [
            'id' => (int) $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'sequence' => (int) $s->sequence,
            'target' => $s->target_minutes,
            'optional' => (bool) $s->is_optional,
            'finish' => $s->isFinish(),
            'menunggu' => $this->menunggu($s, lewatOpsional: false),
            'dilayani' => $this->dilayani($s),
            'selesai' => (int) ($selesaiPerPos[$s->id] ?? 0),
        ])->values()->all();

        $status = EtParticipant::query()->where('event_id', $event->id)
            ->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        return [
            'pos' => $pos,
            'ringkasan' => [
                'total' => (int) $status->sum(),
                'aktif' => (int) ($status[EtParticipant::ACTIVE] ?? 0),
                'selesai' => (int) ($status[EtParticipant::FINISHED] ?? 0),
                'pulang' => (int) ($status[EtParticipant::LEFT] ?? 0),
                'batal' => (int) ($status[EtParticipant::CANCELLED] ?? 0),
            ],
        ];
    }

    public const DOT_TEPAT = 'tepat';        // selesai, ≤ target (atau tanpa target)

    public const DOT_TELAT = 'telat';        // selesai, lewat target

    public const DOT_DILAYANI = 'dilayani';  // sedang dilayani di pos ini (titik putih + radar)

    public const DOT_MENUNGGU = 'menunggu';  // sedang menunggu pos ini (titik putih)

    public const DOT_DILEWATI = 'dilewati';  // pos dilewati (opsional / tanpa scan)

    public const DOT_BELUM = 'belum';        // belum sampai

    /**
     * Tampilan "per peserta": satu baris per peserta (NUP / customer), satu titik
     * per pos. Pos 1 FO (bila ada) selalu titik check-in.
     *
     * @return array{
     *     pos: list<array{id:int, name:string, target:?int, optional:bool, fo:bool}>,
     *     baris: list<array{participant_id:int, name:?string, no_nup:?string, customer_code:?string, status:string,
     *         checked_in_at:?string, since:?string, titik: list<array{state:string, mulai:?string, selesai:?string, detik:?int}>}>
     * }
     */
    public function perjalanan(EtEvent $event, string $cari = ''): array
    {
        $stations = $event->stations()->get();
        $peserta = EtParticipant::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', EtParticipant::CANCELLED)
            ->when(mb_strlen(trim($cari)) >= 2, fn ($q) => $q->where(fn ($w) => $w
                ->where('name_snapshot', 'like', '%'.trim($cari).'%')
                ->orWhere('no_nup', 'like', '%'.trim($cari).'%')
                ->orWhere('customer_code', 'like', '%'.trim($cari).'%')))
            ->orderByRaw("case status when 'active' then 0 when 'finished' then 1 else 2 end")
            ->orderBy('checked_in_at')
            ->orderBy('no_nup')   // check-in bersamaan (mis. 2 NUP satu customer) → urut No. NUP
            ->orderBy('id')
            ->get();

        $kunjungan = EtVisit::query()
            ->whereIn('participant_id', $peserta->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'participant_id', 'station_id', 'started_at', 'ended_at'])
            ->groupBy('participant_id');

        $seqStasiun = $stations->pluck('sequence', 'id');

        $baris = $peserta->toBase()->map(function (EtParticipant $p) use ($stations, $kunjungan, $seqStasiun) {
            $v = ($kunjungan[$p->id] ?? collect())->keyBy('station_id');   // terakhir per pos
            $seqSekarang = (int) ($seqStasiun[$p->current_station_id] ?? 0);
            $seqTerjauh = max($seqSekarang, (int) $v->keys()->map(fn ($id) => $seqStasiun[$id] ?? 0)->max());
            $since = null;

            $titik = $stations->toBase()->map(function (EtStation $s) use ($p, $v, $seqTerjauh, &$since) {
                if ($s->isFoCheckin()) {
                    return ['state' => $p->checked_in_at ? self::DOT_TEPAT : self::DOT_BELUM, 'mulai' => null,
                        'selesai' => $p->checked_in_at?->toIso8601String(), 'detik' => null];
                }

                /** @var EtVisit|null $k */
                $k = $v->get($s->id);
                if ($k && $k->ended_at === null) {
                    $since = $k->started_at?->toIso8601String();

                    return ['state' => self::DOT_DILAYANI, 'mulai' => $since, 'selesai' => null, 'detik' => null];
                }
                if ($k) {
                    $detik = $k->serviceSeconds();
                    $telat = $s->target_minutes && $detik !== null && $detik > $s->target_minutes * 60;

                    return ['state' => $telat ? self::DOT_TELAT : self::DOT_TEPAT, 'mulai' => $k->started_at?->toIso8601String(),
                        'selesai' => $k->ended_at->toIso8601String(), 'detik' => $detik];
                }
                if ($p->status === EtParticipant::ACTIVE && (int) $p->current_station_id === (int) $s->id
                    && $p->current_state === EtParticipant::WAITING) {
                    return ['state' => self::DOT_MENUNGGU, 'mulai' => null, 'selesai' => null, 'detik' => null];
                }

                return ['state' => $s->sequence < $seqTerjauh ? self::DOT_DILEWATI : self::DOT_BELUM,
                    'mulai' => null, 'selesai' => null, 'detik' => null];
            })->values()->all();

            if ($since === null && $p->current_state === EtParticipant::WAITING) {
                $akhir = ($kunjungan[$p->id] ?? collect())->whereNotNull('ended_at')->max('ended_at');
                $since = ($akhir ? Carbon::parse($akhir) : $p->checked_in_at)?->toIso8601String();
            }

            return [
                'participant_id' => (int) $p->id,
                'name' => $p->name_snapshot,
                'no_nup' => $p->no_nup,
                'customer_code' => $p->customer_code,
                'status' => $p->status,
                'checked_in_at' => $p->checked_in_at?->toIso8601String(),
                'since' => $p->status === EtParticipant::ACTIVE ? $since : null,
                'titik' => $titik,
            ];
        })->values()->all();

        return [
            'pos' => $stations->toBase()->map(fn (EtStation $s) => [
                'id' => (int) $s->id,
                'name' => $s->isFoCheckin() ? 'Check-in' : $s->name,
                'target' => $s->target_minutes,
                'optional' => (bool) $s->is_optional,
                'fo' => $s->isFoCheckin(),
            ])->values()->all(),
            'baris' => $baris,
        ];
    }
}
