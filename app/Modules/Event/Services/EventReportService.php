<?php

namespace App\Modules\Event\Services;

use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanLog;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan satu event (tidak membandingkan antar event).
 *
 * - Lama layanan  = selesai − mulai kunjungan di pos.
 * - Waktu tunggu  = mulai di pos ini − selesai kunjungan sebelumnya (pos pertama:
 *                   − check-in FO / kedatangan).
 * - Statistik hanya memakai kunjungan yang sudah selesai.
 *
 * Filter (semua opsional):
 *   dari, sampai : rentang waktu MULAI kunjungan (string tanggal-jam)
 *   pos          : list id et_stations
 *   status       : list status peserta (active, finished, left, cancelled)
 */
class EventReportService
{
    /**
     * Semua kunjungan + waktu tunggu & lama layanan, urut waktu mulai.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>
     */
    public function kunjungan(EtEvent $event, array $filter = []): array
    {
        $stations = $this->stations($event)->keyBy('id');
        $peserta = $this->peserta($event, $filter)->keyBy('id');

        $semua = EtVisit::query()
            ->with('petugas:id,name,full_name')
            ->whereIn('participant_id', $peserta->keys())
            ->whereNotNull('started_at')
            ->orderBy('started_at')->orderBy('id')
            ->get();

        // Waktu tunggu dihitung dari SEMUA kunjungan peserta (sebelum filter pos/waktu),
        // supaya menyaring satu pos tidak mengubah angka tunggunya.
        $tunggu = [];
        foreach ($semua->groupBy('participant_id') as $pid => $vs) {
            $akhirSebelumnya = $peserta[$pid]?->checked_in_at;
            foreach ($vs as $v) {
                $tunggu[$v->id] = $akhirSebelumnya && $v->started_at->gte($akhirSebelumnya)
                    ? (int) $akhirSebelumnya->diffInSeconds($v->started_at)
                    : null;
                if ($v->ended_at && (! $akhirSebelumnya || $v->ended_at->gt($akhirSebelumnya))) {
                    $akhirSebelumnya = $v->ended_at;
                }
            }
        }

        $dari = filled($filter['dari'] ?? null) ? Carbon::parse($filter['dari']) : null;
        $sampai = filled($filter['sampai'] ?? null) ? Carbon::parse($filter['sampai']) : null;
        $pos = array_map('intval', (array) ($filter['pos'] ?? []));

        return $semua->toBase()
            ->filter(fn (EtVisit $v) => (! $dari || $v->started_at->gte($dari))
                && (! $sampai || $v->started_at->lte($sampai))
                && ($pos === [] || in_array((int) $v->station_id, $pos, true)))
            ->map(function (EtVisit $v) use ($stations, $peserta, $tunggu) {
                $s = $stations[$v->station_id] ?? null;
                $p = $peserta[$v->participant_id];
                $layanan = $v->serviceSeconds();

                return [
                    'visit_id' => (int) $v->id,
                    'participant_id' => (int) $p->id,
                    'no_nup' => $p->no_nup,
                    'customer_code' => $p->customer_code,
                    'nama' => $p->name_snapshot,
                    'station_id' => (int) $v->station_id,
                    'pos' => $s?->name,
                    'urutan' => $s?->sequence,
                    'target_menit' => $s?->target_minutes,
                    'mulai' => $v->started_at,
                    'selesai' => $v->ended_at,
                    'layanan_detik' => $layanan,
                    'tunggu_detik' => $tunggu[$v->id] ?? null,
                    'lewat_target' => $s?->target_minutes && $layanan !== null ? $layanan > $s->target_minutes * 60 : null,
                    'cara_selesai' => $v->end_reason,
                    'penanda' => implode(', ', array_filter((array) $v->flags, 'is_string')),
                    'petugas' => $v->petugas ? ($v->petugas->full_name ?: $v->petugas->name) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Statistik per pos.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>
     */
    public function perPos(EtEvent $event, array $filter = []): array
    {
        $kunjungan = collect($this->kunjungan($event, $filter))->groupBy('station_id');
        $pos = array_map('intval', (array) ($filter['pos'] ?? []));

        return $this->stations($event)
            ->filter(fn (EtStation $s) => $pos === [] || in_array((int) $s->id, $pos, true))
            ->map(function (EtStation $s) use ($kunjungan) {
                $vs = $kunjungan->get($s->id, collect());
                $selesai = $vs->whereNotNull('layanan_detik');
                $layanan = $selesai->pluck('layanan_detik')->all();
                $tunggu = $vs->pluck('tunggu_detik')->filter(fn ($x) => $x !== null)->values()->all();
                $lewat = $selesai->where('lewat_target', true)->count();

                return [
                    'station_id' => (int) $s->id,
                    'urutan' => (int) $s->sequence,
                    'pos' => $s->name,
                    'opsional' => (bool) $s->is_optional,
                    'target_menit' => $s->target_minutes,
                    'kunjungan' => $vs->count(),
                    'selesai' => $selesai->count(),
                    'berjalan' => $vs->count() - $selesai->count(),
                    'layanan_rata' => self::rata($layanan),
                    'layanan_median' => self::persentil($layanan, 50),
                    'layanan_p90' => self::persentil($layanan, 90),
                    'tunggu_rata' => self::rata($tunggu),
                    'tunggu_p90' => self::persentil($tunggu, 90),
                    'lewat_target' => $s->target_minutes ? $lewat : null,
                    'lewat_target_persen' => $s->target_minutes && $selesai->count() ? round($lewat * 100 / $selesai->count(), 1) : null,
                ];
            })->values()->all();
    }

    /**
     * Perjalanan per peserta, dikelompokkan per customer (NUP-NUP satu customer berdekatan).
     *
     * @param  array<string, mixed>  $filter
     * @return list<array<string, mixed>>
     */
    public function perCustomer(EtEvent $event, array $filter = []): array
    {
        $kunjungan = collect($this->kunjungan($event, array_merge($filter, ['pos' => []])))->groupBy('participant_id');
        $stations = $this->stations($event);

        return $this->peserta($event, $filter)
            ->sortBy([['customer_code', 'asc'], ['no_nup', 'asc'], ['id', 'asc']])
            ->toBase()
            ->map(function (EtParticipant $p) use ($kunjungan, $stations) {
                $vs = $kunjungan->get($p->id, collect())->keyBy('station_id');
                $total = $p->checked_in_at && $p->finished_at ? (int) $p->checked_in_at->diffInSeconds($p->finished_at) : null;

                return [
                    'participant_id' => (int) $p->id,
                    'customer_code' => $p->customer_code,
                    'nama' => $p->name_snapshot,
                    'no_nup' => $p->no_nup,
                    'status' => $p->status,
                    'check_in' => $p->checked_in_at,
                    'selesai' => $p->finished_at,
                    'total_detik' => $total,
                    'tunggu_total_detik' => (int) $vs->sum(fn ($v) => $v['tunggu_detik'] ?? 0),
                    'pos' => $stations->mapWithKeys(fn (EtStation $s) => [
                        $s->id => isset($vs[$s->id]) ? $vs[$s->id]['layanan_detik'] : null,
                    ])->all(),
                    'dilewati' => $stations->filter(fn (EtStation $s) => ! isset($vs[$s->id]))->pluck('name')->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * Throughput per jam: berapa kunjungan selesai per pos, dan berapa peserta tuntas.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array{jam:string, per_pos:array<int,int>, tuntas:int}>
     */
    public function throughputPerJam(EtEvent $event, array $filter = []): array
    {
        $jam = [];
        foreach ($this->kunjungan($event, $filter) as $v) {
            if ($v['selesai'] === null) {
                continue;
            }
            $k = $v['selesai']->format('Y-m-d H:00');
            $jam[$k]['per_pos'][$v['station_id']] = ($jam[$k]['per_pos'][$v['station_id']] ?? 0) + 1;
        }
        foreach ($this->peserta($event, $filter) as $p) {
            if ($p->finished_at) {
                $k = $p->finished_at->format('Y-m-d H:00');
                $jam[$k]['tuntas'] = ($jam[$k]['tuntas'] ?? 0) + 1;
            }
        }
        ksort($jam);

        return collect($jam)->map(fn ($v, $k) => [
            'jam' => $k,
            'per_pos' => $v['per_pos'] ?? [],
            'tuntas' => $v['tuntas'] ?? 0,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function ringkasan(EtEvent $event, array $filter = []): array
    {
        $peserta = $this->peserta($event, $filter);
        $total = $peserta->map(fn (EtParticipant $p) => $p->checked_in_at && $p->finished_at ? (int) $p->checked_in_at->diffInSeconds($p->finished_at) : null)
            ->filter(fn ($x) => $x !== null)->values()->all();
        $kunjungan = collect($this->kunjungan($event, $filter));

        return [
            'peserta' => $peserta->count(),
            'aktif' => $peserta->where('status', EtParticipant::ACTIVE)->count(),
            'selesai' => $peserta->where('status', EtParticipant::FINISHED)->count(),
            'pulang' => $peserta->where('status', EtParticipant::LEFT)->count(),
            'batal' => $peserta->where('status', EtParticipant::CANCELLED)->count(),
            'kunjungan' => $kunjungan->count(),
            'total_rata' => self::rata($total),
            'total_median' => self::persentil($total, 50),
            'total_p90' => self::persentil($total, 90),
            'tunggu_rata' => self::rata($kunjungan->pluck('tunggu_detik')->filter(fn ($x) => $x !== null)->values()->all()),
            'koreksi' => $kunjungan->filter(fn ($v) => str_contains((string) $v['penanda'], 'koreksi'))->count(),
        ];
    }

    /**
     * Log scan (untuk audit), terbaru dulu.
     *
     * @param  array<string, mixed>  $filter
     * @return Collection<int, EtScanLog>
     */
    public function logScan(EtEvent $event, array $filter = []): Collection
    {
        return EtScanLog::query()
            ->with(['scanPoint.station:id,name'])
            ->where('event_id', $event->id)
            ->when(filled($filter['dari'] ?? null), fn ($q) => $q->where('server_time', '>=', Carbon::parse($filter['dari'])))
            ->when(filled($filter['sampai'] ?? null), fn ($q) => $q->where('server_time', '<=', Carbon::parse($filter['sampai'])))
            ->orderByDesc('server_time')->orderByDesc('id')
            ->get();
    }

    // ── statistik ───────────────────────────────────────────────────────

    /** @param list<int|float> $nilai */
    public static function rata(array $nilai): ?int
    {
        return $nilai === [] ? null : (int) round(array_sum($nilai) / count($nilai));
    }

    /**
     * Persentil (interpolasi linear, sama dengan PERCENTILE.INC di Excel).
     *
     * @param  list<int|float>  $nilai
     */
    public static function persentil(array $nilai, float $p): ?int
    {
        if ($nilai === []) {
            return null;
        }
        sort($nilai);
        $pos = ($p / 100) * (count($nilai) - 1);
        $bawah = (int) floor($pos);
        $atas = (int) ceil($pos);

        return (int) round($nilai[$bawah] + ($nilai[$atas] - $nilai[$bawah]) * ($pos - $bawah));
    }

    /** Detik → "mm:ss" / "j:mm:ss"; null → "—". */
    public static function durasi(?int $detik): string
    {
        if ($detik === null) {
            return '—';
        }
        $j = intdiv($detik, 3600);
        $m = intdiv($detik % 3600, 60);
        $s = $detik % 60;

        return $j > 0 ? sprintf('%d:%02d:%02d', $j, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }

    // ── internal ────────────────────────────────────────────────────────

    /** @return Collection<int, EtStation> */
    private function stations(EtEvent $event): Collection
    {
        return EtStation::query()->where('event_id', $event->id)->where('type', '!=', EtStation::FO_CHECKIN)
            ->orderBy('sequence')->get();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return Collection<int, EtParticipant>
     */
    private function peserta(EtEvent $event, array $filter): Collection
    {
        $status = array_values(array_filter((array) ($filter['status'] ?? [])));

        return EtParticipant::query()->where('event_id', $event->id)
            ->when($status !== [], fn ($q) => $q->whereIn('status', $status))
            ->get();
    }
}
