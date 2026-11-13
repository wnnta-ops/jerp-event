<?php

namespace App\Modules\Event\Services;

use App\Modules\Event\Data\FoParticipantData;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtParticipant;
use App\Modules\Event\Models\EtStation;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Pos 1 = Aktivasi NUP di FO. Modul Event tidak menambah apa pun di reception;
 * kehadiran dibaca dari tgl_check_in FO lewat FoNupLookup lalu dijadikan
 * peserta (satu per NUP) yang menunggu di pos 2.
 */
class SyncCheckInService
{
    /** Tumpang-tindih jendela sinkron, supaya aktivasi yang commit telat tidak terlewat. */
    private const OVERLAP_MINUTES = 5;

    public function __construct(private readonly FoNupLookup $fo) {}

    /**
     * @return array{baru:int, diperbarui:int, dibatalkan:int}
     */
    public function syncEvent(EtEvent $event): array
    {
        $tenant = app(TenantContext::class);
        $tenantSebelum = $tenant->id();
        $tenant->set((int) $event->tenant_id);

        try {
            $mulai = now();
            $since = $event->last_synced_at?->copy()->subMinutes(self::OVERLAP_MINUTES);
            $hasil = ['baru' => 0, 'diperbarui' => 0, 'dibatalkan' => 0];

            foreach ($this->fo->checkedInSince((int) $event->fo_nup_event_id, $since) as $data) {
                [, $baru] = $this->upsert($event, $data);
                $hasil[$baru ? 'baru' : 'diperbarui']++;
            }

            $aktif = EtParticipant::query()
                ->where('event_id', $event->id)
                ->where('status', EtParticipant::ACTIVE)
                ->pluck('fo_nup_registration_id');

            $batal = $this->fo->cancelledSince((int) $event->fo_nup_event_id, $aktif);
            if ($batal->isNotEmpty()) {
                EtParticipant::query()
                    ->where('event_id', $event->id)
                    ->where('status', EtParticipant::ACTIVE)
                    ->whereIn('fo_nup_registration_id', $batal)
                    ->get()
                    ->each(function (EtParticipant $p) use (&$hasil) {
                        $p->update(['status' => EtParticipant::CANCELLED]);
                        $hasil['dibatalkan']++;
                    });
            }

            $event->forceFill(['last_synced_at' => $mulai])->save();

            return $hasil;
        } finally {
            $tenant->set($tenantSebelum);
        }
    }

    /**
     * Buat / segarkan peserta dari potret FO. Idempoten — dipakai sinkron dan
     * ScanService (NUP yang di-scan sebelum sinkron sempat jalan).
     *
     * @return array{0: EtParticipant, 1: bool} [peserta, baru dibuat?]
     */
    public function upsert(EtEvent $event, FoParticipantData $data): array
    {
        $snapshot = [
            'no_nup' => $data->no_nup,
            'customer_code' => $data->customer_code,
            'name_snapshot' => $data->name,
            'phone_snapshot' => $data->phone,
        ];

        $p = EtParticipant::query()->createOrFirst(
            ['event_id' => $event->id, 'fo_nup_registration_id' => $data->nup_registration_id],
            $snapshot + [
                'tenant_id' => $event->tenant_id,
                'checked_in_at' => $data->tgl_check_in,
                'status' => EtParticipant::ACTIVE,
                'current_station_id' => $this->stationAfterCheckin($event)?->id,
                'current_state' => EtParticipant::WAITING,
            ],
        );

        if ($p->wasRecentlyCreated) {
            return [$p, true];
        }

        $ubah = $snapshot;
        // Aktivasi dibatalkan lalu diaktivasi ulang di FO → peserta hidup lagi dari awal antrean.
        if ($p->status === EtParticipant::CANCELLED && $data->isCheckedIn()) {
            $ubah += [
                'status' => EtParticipant::ACTIVE,
                'checked_in_at' => $data->tgl_check_in,
                'current_station_id' => $this->stationAfterCheckin($event)?->id,
                'current_state' => EtParticipant::WAITING,
            ];
        } elseif ($p->checked_in_at === null && $data->tgl_check_in !== null) {
            $ubah['checked_in_at'] = $data->tgl_check_in;
        }

        $p->fill($ubah);
        if ($p->isDirty()) {
            DB::transaction(fn () => $p->save());
        }

        return [$p, false];
    }

    private function stationAfterCheckin(EtEvent $event): ?EtStation
    {
        return $event->stations()->where('type', '!=', EtStation::FO_CHECKIN)->orderBy('sequence')->first();
    }
}
