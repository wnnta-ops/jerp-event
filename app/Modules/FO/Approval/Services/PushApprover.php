<?php

namespace App\Modules\FO\Approval\Services;

use App\Models\User;
use App\Modules\FO\Approval\Models\PushSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi push app approver — dijalankan tiap menit (php artisan pwa:push).
 *
 * Untuk tiap user yang punya langganan, FO menanyakan BO (lewat BoInboxBridge) daftar
 * "menunggu saya" dan "ajuan saya", lalu membandingkan dengan jejak terakhir (fo_push_jejak):
 *   · giliran : dokumen yang BARU masuk ke tahap saya
 *   · ajuan   : dokumen saya yang statusnya baru berubah jadi disetujui / ditolak
 *   · harian  : sekali sehari pada jam_harian, bila masih ada yang menunggu
 * Putaran pertama per user hanya mencatat jejak (tidak mengirim apa pun), supaya menyalakan
 * notifikasi tidak langsung membanjiri HP dengan dokumen lama. BO tidak bisa dihubungi →
 * user itu dilewati dan jejaknya tidak diubah.
 */
class PushApprover
{
    /** Lebih dari ini dokumen baru sekaligus → satu notifikasi ringkasan. */
    private const MAKS_SATUAN = 3;

    public function __construct(
        private readonly BoInboxBridge $bridge,
        private readonly PushPengirim $pengirim,
    ) {}

    /** @return array{user:int, terkirim:int} */
    public function jalankan(?Carbon $kini = null): array
    {
        $kini ??= now();
        $hasil = ['user' => 0, 'terkirim' => 0];
        if (! $this->pengirim->siap()) {
            return $hasil;
        }

        $perUser = PushSubscription::query()->get()->groupBy('user_id');
        foreach (User::query()->whereKey($perUser->keys())->get() as $user) {
            $hasil['user']++;
            $hasil['terkirim'] += $this->cekUser($user, $perUser[$user->id], $kini);
        }

        return $hasil;
    }

    /** @param  Collection<int, PushSubscription>  $langganan */
    public function cekUser(User $user, $langganan, Carbon $kini): int
    {
        $perlu = $this->bridge->get((string) $user->email);
        $ajuan = $this->bridge->get((string) $user->email, '/pengajuan');
        if ($perlu['status'] !== 200 || $ajuan['status'] !== 200) {
            return 0;
        }
        $perluRows = collect($perlu['body']['data'] ?? []);
        $ajuanStatus = collect($ajuan['body']['data'] ?? [])->mapWithKeys(fn ($r) => [(string) $r['id'] => (string) $r['status']]);

        $jejak = DB::table('fo_push_jejak')->where('user_id', $user->id)->first();
        $simpan = fn () => DB::table('fo_push_jejak')->updateOrInsert(['user_id' => $user->id], [
            'perlu_ids' => json_encode($perluRows->pluck('id')->values()),
            'ajuan_status' => json_encode($ajuanStatus),
            'updated_at' => $kini,
            'created_at' => $jejak->created_at ?? $kini,
        ]);

        $pesan = ['giliran' => [], 'ajuan' => []];
        if ($jejak) {
            $lama = collect(json_decode((string) $jejak->perlu_ids, true) ?: []);
            $baru = $perluRows->reject(fn ($r) => $lama->contains($r['id']))->values();
            $pesan['giliran'] = $this->pesanGiliran($baru);

            $statusLama = json_decode((string) $jejak->ajuan_status, true) ?: [];
            $diputuskan = collect($ajuan['body']['data'] ?? [])->filter(fn ($r) => isset($statusLama[(string) $r['id']])
                && in_array($r['status'], ['approved', 'rejected'], true)
                && $statusLama[(string) $r['id']] !== $r['status']);
            $pesan['ajuan'] = $diputuskan->map(fn ($r) => [
                'title' => 'Ajuan Anda '.($r['status'] === 'approved' ? 'disetujui' : 'ditolak'),
                'body' => $this->baris($r),
                'url' => $this->url($r['id']),
                'tag' => 'ajuan-'.$r['id'],
            ])->values()->all();
        }
        $simpan();

        $terkirim = 0;
        foreach ($langganan as $s) {
            $kirim = [];
            if ($s->giliran) {
                array_push($kirim, ...$pesan['giliran']);
            }
            if ($s->ajuan) {
                array_push($kirim, ...$pesan['ajuan']);
            }
            if ($s->harian && $this->waktunyaHarian($s, $kini)) {
                $s->harian_terakhir = $kini->copy()->startOfDay();
                if ($perluRows->isNotEmpty()) {
                    $urgent = $perluRows->where('kategori', 'urgent')->count();
                    $kirim[] = [
                        'title' => 'Pengingat approval',
                        'body' => $perluRows->count().' dokumen menunggu Anda'.($urgent ? " ({$urgent} urgent)" : '').'.',
                        'url' => $this->url(),
                        'tag' => 'harian',
                    ];
                }
            }
            foreach ($kirim as $p) {
                if (! $this->pengirim->kirim($s, $p)) {
                    $s->delete();

                    continue 2;
                }
                $terkirim++;
                $s->terkirim_at = $kini;
            }
            if ($s->isDirty()) {
                $s->save();
            }
        }

        return $terkirim;
    }

    /** @param  Collection<int, array<string,mixed>>  $baru */
    private function pesanGiliran($baru): array
    {
        if ($baru->isEmpty()) {
            return [];
        }
        if ($baru->count() > self::MAKS_SATUAN) {
            $urgent = $baru->where('kategori', 'urgent')->count();

            return [[
                'title' => $baru->count().' dokumen baru menunggu Anda',
                'body' => $urgent ? "{$urgent} di antaranya urgent." : 'Buka Approval inbox untuk memeriksa.',
                'url' => $this->url(),
                'tag' => 'giliran',
            ]];
        }

        return $baru->map(fn ($r) => [
            'title' => ($r['kategori'] ?? '') === 'urgent' ? 'URGENT · Perlu persetujuan' : 'Perlu persetujuan · '.($r['type'] ?? ''),
            'body' => $this->baris($r),
            'url' => $this->url($r['id']),
            'tag' => 'giliran-'.$r['id'],
        ])->values()->all();
    }

    private function waktunyaHarian(PushSubscription $s, Carbon $kini): bool
    {
        $hariIni = $kini->copy()->startOfDay();
        if ($s->harian_terakhir && $s->harian_terakhir->greaterThanOrEqualTo($hariIni)) {
            return false;
        }
        [$j, $m] = array_map('intval', explode(':', $s->jam_harian ?: '08:00') + [0, 0]);

        return $kini->greaterThanOrEqualTo($hariIni->copy()->setTime($j, $m));
    }

    /** "INV-1 — Invoice PT Beton · Rp 120.500.000" */
    private function baris(array $r): string
    {
        $nominal = isset($r['amount']) ? ' · Rp '.number_format((float) $r['amount'], 0, ',', '.') : '';

        return implode(' — ', array_filter([$r['number'] ?? null, $r['subject'] ?? $r['type'] ?? null])).$nominal;
    }

    private function url(?int $id = null): string
    {
        $dasar = (string) config('push.buka_url', './');

        return $id ? $dasar.(str_contains($dasar, '?') ? '&' : '?').'buka='.$id : $dasar;
    }
}
