<?php

namespace App\Modules\Event\Services;

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
     * @return list<array{participant_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string}>
     */
    public function menunggu(EtStation $station): array
    {
        $peserta = EtParticipant::query()
            ->where('event_id', $station->event_id)
            ->where('status', EtParticipant::ACTIVE)
            ->whereIn('current_station_id', $this->stasiunMenuju($station))
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
        })->sortBy('since')->values()->all();
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
     * @return list<array{participant_id:int, visit_id:int, no_nup:?string, name:?string, customer_code:?string, since:?string, flags:list<string>}>
     */
    public function dilayani(EtStation $station): array
    {
        return EtVisit::query()
            ->with('participant:id,no_nup,name_snapshot,customer_code')
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
            ])->values()->all();
    }
}
