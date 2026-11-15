<?php

namespace App\Modules\Event\Services;

use App\Models\User;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtScanPoint;
use App\Modules\Event\Models\EtStation;
use App\Modules\Event\Models\EtVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Setup event: buat / ubah / buka / tutup / duplikat. Dipakai wizard Filament.
 *
 * Dua jenis event:
 *   fo      — terhubung ke event NUP FO. Pos 1 = Aktivasi NUP (dibaca dari FO),
 *             peserta dilacak per NUP.
 *   mandiri — tanpa event NUP. Semua pos di-scan di modul ini; peserta = customer
 *             (QR customer FO), dibuat saat scan pertama.
 *
 * Bentuk data (sama untuk buat & ubah):
 *   jenis (fo|mandiri), name, event_date, venue, fo_nup_event_id (wajib bila fo)
 *   pos   : [{code, name, target_minutes}]  — berurutan; untuk jenis fo pos 1 (FO)
 *                                              ditambahkan otomatis di depan;
 *                                              pos terakhir otomatis 'finish'
 *   titik : [{code, mode: ganda|single, label_masuk, label_keluar, label_single}]
 *   akses : [{user_id, titik: ['KODE:masuk', …], can_live_board, can_reports}]
 *
 * Pos & titik scan hanya bisa diubah selama event masih draft — setelah dibuka,
 * kunjungan sudah menempel ke pos itu.
 */
class EventSetupService
{
    public const MODE_GANDA = 'ganda';

    public const MODE_SINGLE = 'single';

    public const FO_CODE = 'FO';

    public const JENIS_FO = 'fo';

    public const JENIS_MANDIRI = 'mandiri';

    public function __construct(
        private readonly FoNupLookup $fo,
        private readonly SyncCheckInService $sync,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): EtEvent
    {
        $this->validasi($data);

        $event = DB::transaction(function () use ($data, $by) {
            $event = EtEvent::create($this->detail($data) + [
                'status' => EtEvent::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->bangunStruktur($event, $data);
            $this->simpanAkses($event, (array) ($data['akses'] ?? []));

            return $event;
        });

        if (! empty($data['buka_sekarang'])) {
            $this->open($event);
        }

        return $event->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(EtEvent $event, array $data): EtEvent
    {
        $draft = $event->status === EtEvent::DRAFT;
        if (! $draft) {
            // Pos & titik terkunci; jenis & event NUP juga tak boleh ganti setelah dibuka.
            $data['jenis'] = $event->isMandiri() ? self::JENIS_MANDIRI : self::JENIS_FO;
            $data['fo_nup_event_id'] = $event->fo_nup_event_id;
        }
        $this->validasi($data, strukturDiperiksa: $draft);

        DB::transaction(function () use ($event, $data, $draft) {
            $event->update($this->detail($data));
            if ($draft) {
                $this->hapusStruktur($event);
                $this->bangunStruktur($event, $data);
            }
            $this->simpanAkses($event, (array) ($data['akses'] ?? []));
        });

        if ($draft && ! empty($data['buka_sekarang'])) {
            $this->open($event);
        }

        return $event->refresh();
    }

    public function open(EtEvent $event): EtEvent
    {
        if ($event->status !== EtEvent::DRAFT) {
            throw new RuntimeException('Hanya event berstatus draft yang bisa dibuka.');
        }
        $stations = $event->stations()->withCount('scanPoints')->get();
        if ($stations->where('type', '!=', EtStation::FO_CHECKIN)->isEmpty()) {
            throw new RuntimeException('Event belum punya pos untuk di-scan.');
        }
        if ($stations->where('type', '!=', EtStation::FO_CHECKIN)->contains(fn ($s) => $s->scan_points_count === 0)) {
            throw new RuntimeException('Ada pos tanpa titik scan.');
        }

        $event->update(['status' => EtEvent::OPEN, 'opened_at' => now()]);

        // Tarik peserta yang sudah aktivasi di FO. Gagal sinkron tidak membatalkan pembukaan —
        // job tiap menit akan mencoba lagi.
        try {
            $this->sync->syncEvent($event);
        } catch (Throwable $e) {
            Log::warning('[event] sinkron saat buka event gagal', ['event_id' => $event->id, 'error' => $e->getMessage()]);
        }

        return $event->refresh();
    }

    /**
     * Tutup event: akses scanner berakhir (ScanService menolak event non-open),
     * kunjungan yang masih terbuka ditutup dengan alasan event_closed.
     */
    public function close(EtEvent $event, User $by): EtEvent
    {
        if ($event->status !== EtEvent::OPEN) {
            throw new RuntimeException('Hanya event yang sedang dibuka yang bisa ditutup.');
        }

        DB::transaction(function () use ($event, $by) {
            $now = now();
            $pesertaIds = EtParticipant::query()->where('event_id', $event->id)->pluck('id');

            EtVisit::query()
                ->whereIn('participant_id', $pesertaIds)
                ->whereNull('ended_at')
                ->get()
                ->each(fn (EtVisit $v) => $v->forceFill([
                    'ended_at' => $now, 'ended_by' => $by->id, 'end_reason' => EtVisit::END_EVENT_CLOSED,
                ])->save());

            EtParticipant::query()
                ->where('event_id', $event->id)
                ->where('current_state', EtParticipant::IN_SERVICE)
                ->update(['current_state' => EtParticipant::DONE]);

            $event->update(['status' => EtEvent::CLOSED, 'closed_at' => $now]);
        });

        return $event->refresh();
    }

    /** Salin pos, titik scan & akses ke event draft baru. Peserta & kunjungan tidak ikut. */
    public function duplicate(EtEvent $asal, User $by): EtEvent
    {
        $data = $this->formData($asal);
        $data['name'] = Str::limit($asal->name, 240, '').' (salinan)';
        $data['buka_sekarang'] = false;

        return $this->create($data, $by);
    }

    /**
     * Event → bentuk data wizard (untuk mengisi form ubah & duplikat).
     *
     * @return array<string, mixed>
     */
    public function formData(EtEvent $event): array
    {
        $stations = $event->stations()->with('scanPoints')->get()->reject(fn (EtStation $s) => $s->isFoCheckin())->values();
        $kunciTitik = [];

        $titik = $stations->map(function (EtStation $s) use (&$kunciTitik) {
            $baris = ['code' => $s->code, 'name' => $s->name, 'mode' => self::MODE_GANDA,
                'label_masuk' => null, 'label_keluar' => null, 'label_single' => null];
            foreach ($s->scanPoints as $p) {
                $kunciTitik[$p->id] = $s->code.':'.$p->kind;
                if ($p->kind === EtScanPoint::SINGLE) {
                    $baris['mode'] = self::MODE_SINGLE;
                }
                $baris['label_'.$p->kind] = $p->label;
            }

            return $baris;
        })->all();

        $akses = EtAccess::query()->where('event_id', $event->id)->get()
            ->groupBy('user_id')
            ->map(fn ($rows, $userId) => [
                'user_id' => (int) $userId,
                'titik' => $rows->pluck('scan_point_id')->filter()->map(fn ($id) => $kunciTitik[$id] ?? null)->filter()->values()->all(),
                'can_live_board' => $rows->contains('can_live_board', true),
                'can_reports' => $rows->contains('can_reports', true),
            ])->values()->all();

        return [
            'jenis' => $event->isMandiri() ? self::JENIS_MANDIRI : self::JENIS_FO,
            'name' => $event->name,
            'event_date' => $event->event_date->toDateString(),
            'venue' => $event->venue,
            'fo_nup_event_id' => $event->fo_nup_event_id,
            'pos' => $stations->map(fn (EtStation $s) => [
                'code' => $s->code, 'name' => $s->name, 'target_minutes' => $s->target_minutes,
            ])->all(),
            'titik' => $titik,
            'akses' => $akses,
        ];
    }

    /**
     * Baris titik scan bawaan untuk daftar pos: Masuk + Keluar per pos.
     * Baris lama (kode sama) dipertahankan supaya ubahan nama/mode tidak hilang.
     *
     * @param  array<int, array<string, mixed>>  $pos
     * @param  array<int, array<string, mixed>>  $titikLama
     * @return list<array<string, mixed>>
     */
    public function titikBawaan(array $pos, array $titikLama = []): array
    {
        $lama = collect($titikLama)->keyBy(fn ($t) => strtoupper((string) ($t['code'] ?? '')));

        return collect($pos)->values()->map(function ($p) use ($lama) {
            $code = strtoupper(trim((string) ($p['code'] ?? '')));
            $name = trim((string) ($p['name'] ?? ''));
            $ada = $lama->get($code, []);

            return [
                'code' => $code,
                'name' => $name,
                'mode' => $ada['mode'] ?? self::MODE_GANDA,
                'label_masuk' => ($ada['label_masuk'] ?? null) ?: $name.' · Masuk',
                'label_keluar' => ($ada['label_keluar'] ?? null) ?: $name.' · Keluar',
                'label_single' => ($ada['label_single'] ?? null) ?: $name,
            ];
        })->all();
    }

    /**
     * Pilihan titik untuk langkah Akses: 'KODE:kind' => label.
     *
     * @param  array<int, array<string, mixed>>  $titik
     * @return array<string, string>
     */
    public function pilihanTitik(array $titik): array
    {
        $opsi = [];
        foreach ($titik as $t) {
            $code = strtoupper((string) ($t['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (($t['mode'] ?? self::MODE_GANDA) === self::MODE_SINGLE) {
                $opsi[$code.':single'] = (string) ($t['label_single'] ?? $code);
            } else {
                $opsi[$code.':masuk'] = (string) ($t['label_masuk'] ?? $code.' · Masuk');
                $opsi[$code.':keluar'] = (string) ($t['label_keluar'] ?? $code.' · Keluar');
            }
        }

        return $opsi;
    }

    // ── internal ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public static function isMandiri(array $data): bool
    {
        return ($data['jenis'] ?? self::JENIS_FO) === self::JENIS_MANDIRI;
    }

    /** @param array<string, mixed> $data */
    private function detail(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'event_date' => $data['event_date'],
            'venue' => filled($data['venue'] ?? null) ? trim((string) $data['venue']) : null,
            'fo_nup_event_id' => self::isMandiri($data) ? null : (int) $data['fo_nup_event_id'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function validasi(array $data, bool $strukturDiperiksa = true): void
    {
        if (blank($data['name'] ?? null) || blank($data['event_date'] ?? null)) {
            throw new RuntimeException('Nama dan tanggal event wajib diisi.');
        }
        if (! self::isMandiri($data) && ! $this->fo->nupEvent((int) ($data['fo_nup_event_id'] ?? 0))) {
            throw new RuntimeException('Event NUP FO tidak ditemukan.');
        }
        if (! $strukturDiperiksa) {
            return;
        }

        $kode = collect((array) ($data['pos'] ?? []))->map(fn ($p) => strtoupper(trim((string) ($p['code'] ?? ''))));
        if ($kode->isEmpty()) {
            throw new RuntimeException(self::isMandiri($data) ? 'Minimal satu pos.' : 'Minimal satu pos setelah Aktivasi NUP.');
        }
        if ($kode->contains('') || $kode->contains(self::FO_CODE)) {
            throw new RuntimeException('Kode pos wajib diisi dan tidak boleh "'.self::FO_CODE.'".');
        }
        if ($kode->duplicates()->isNotEmpty()) {
            throw new RuntimeException('Kode pos tidak boleh sama: '.$kode->duplicates()->unique()->implode(', ').'.');
        }
    }

    /** @param array<string, mixed> $data */
    private function bangunStruktur(EtEvent $event, array $data): void
    {
        // Event terhubung FO: pos 1 = Aktivasi NUP (dibaca dari FO). Mandiri: tidak ada.
        $awal = 1;
        if (! $event->isMandiri()) {
            EtStation::create([
                'tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'code' => self::FO_CODE,
                'name' => 'Aktivasi NUP (FO)', 'sequence' => 1, 'type' => EtStation::FO_CHECKIN,
            ]);
            $awal = 2;
        }

        $pos = array_values((array) $data['pos']);
        $titik = collect($this->titikBawaan($pos, (array) ($data['titik'] ?? [])))->keyBy('code');
        $terakhir = count($pos) - 1;

        foreach ($pos as $i => $p) {
            $code = strtoupper(trim((string) $p['code']));
            $station = EtStation::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'code' => $code,
                'name' => trim((string) $p['name']) ?: $code,
                'sequence' => $i + $awal,
                'type' => $i === $terakhir ? EtStation::FINISH : EtStation::NORMAL,
                'target_minutes' => filled($p['target_minutes'] ?? null) ? (int) $p['target_minutes'] : null,
            ]);

            $t = $titik->get($code);
            $jenis = ($t['mode'] ?? self::MODE_GANDA) === self::MODE_SINGLE
                ? [EtScanPoint::SINGLE]
                : [EtScanPoint::MASUK, EtScanPoint::KELUAR];

            foreach ($jenis as $kind) {
                EtScanPoint::create([
                    'tenant_id' => $event->tenant_id,
                    'station_id' => $station->id,
                    'kind' => $kind,
                    'label' => trim((string) ($t['label_'.$kind] ?? '')) ?: $station->name.' · '.ucfirst($kind),
                ]);
            }
        }
    }

    private function hapusStruktur(EtEvent $event): void
    {
        // Draft saja: belum ada kunjungan. forceDelete supaya FK cascade (titik & akses-titik) berjalan.
        $event->stations()->get()->each(function (EtStation $s) {
            $s->scanPoints()->get()->each(fn (EtScanPoint $p) => $p->forceDelete());
            $s->forceDelete();
        });
    }

    /** @param array<int, array<string, mixed>> $akses */
    private function simpanAkses(EtEvent $event, array $akses): void
    {
        EtAccess::query()->where('event_id', $event->id)->get()->each(fn (EtAccess $a) => $a->forceDelete());

        $titikId = $event->stations()->with('scanPoints')->get()
            ->flatMap(fn (EtStation $s) => $s->scanPoints->mapWithKeys(fn (EtScanPoint $p) => [$s->code.':'.$p->kind => $p->id]));

        foreach (collect($akses)->filter(fn ($a) => filled($a['user_id'] ?? null))->groupBy('user_id') as $userId => $baris) {
            $kunci = $baris->flatMap(fn ($a) => (array) ($a['titik'] ?? []))->unique();
            $flags = [
                'can_live_board' => $baris->contains(fn ($a) => ! empty($a['can_live_board'])),
                'can_reports' => $baris->contains(fn ($a) => ! empty($a['can_reports'])),
            ];
            $ids = $kunci->map(fn ($k) => $titikId[$k] ?? null)->filter()->values();

            if ($ids->isEmpty()) {
                if ($flags['can_live_board'] || $flags['can_reports']) {
                    EtAccess::create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'user_id' => (int) $userId, 'scan_point_id' => null] + $flags);
                }

                continue;
            }

            foreach ($ids as $id) {
                EtAccess::create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'user_id' => (int) $userId, 'scan_point_id' => $id] + $flags);
            }
        }
    }
}
