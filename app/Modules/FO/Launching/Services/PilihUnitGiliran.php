<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Launching\Models\PilihUnitSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Giliran HP di Pilih Unit (Setup Pilih Unit per cluster).
 *
 * - Nomor HP dipilih manual di tiap HP; satu nomor hanya boleh dipegang satu HP. Pegangan
 *   disimpan di cache dan diperpanjang tiap cek giliran — HP yang tertutup/mati melepasnya
 *   sendiri sesudah DETIK_PEGANG.
 * - Giliran maju otomatis sesudah HP yang bergiliran berhasil memilih unit, atau oleh admin
 *   lewat tombol Lanjut. Sesudah HP terakhir kembali ke HP 1.
 */
class PilihUnitGiliran
{
    public const DETIK_PEGANG = 20;

    public function setup(?int $lokasiId): ?PilihUnitSetup
    {
        return $lokasiId ? PilihUnitSetup::query()->where('lokasi_id', $lokasiId)->first() : null;
    }

    /** Setup yang berlaku untuk halaman Pilih Unit (bawaan bila cluster belum di-setup). */
    public function status(?int $lokasiId): array
    {
        $s = $this->setup($lokasiId);
        $aktif = (bool) $s?->giliran_aktif;

        return [
            'aktif' => $aktif,
            'jumlah' => $aktif ? $s->jumlah_hp : 0,
            'giliran' => $aktif ? $s->giliran_hp : null,
            'terpakai' => $aktif ? $this->hpTerpakai($lokasiId, $s->jumlah_hp) : [],
            'timer_aktif' => $s ? $s->timer_aktif : true,
            'timer_menit' => $s ? max(1, $s->timer_menit) : 2,
        ];
    }

    /** Pegang nomor HP untuk token ini. false = sudah dipegang HP lain. */
    public function pegang(int $lokasiId, int $hp, string $token): bool
    {
        $key = $this->kunci($lokasiId, $hp);
        if (Cache::add($key, $token, self::DETIK_PEGANG)) {
            return true;
        }
        if (Cache::get($key) !== $token) {
            return false;
        }
        Cache::put($key, $token, self::DETIK_PEGANG);

        return true;
    }

    public function lepas(int $lokasiId, int $hp, string $token): void
    {
        if (Cache::get($this->kunci($lokasiId, $hp)) === $token) {
            Cache::forget($this->kunci($lokasiId, $hp));
        }
    }

    /** @return list<int> */
    public function hpTerpakai(int $lokasiId, int $jumlah): array
    {
        $terpakai = [];
        for ($hp = 1; $hp <= $jumlah; $hp++) {
            if (Cache::has($this->kunci($lokasiId, $hp))) {
                $terpakai[] = $hp;
            }
        }

        return $terpakai;
    }

    /** Lempar bila giliran aktif dan bukan giliran HP ini. */
    public function pastikanGiliran(?int $lokasiId, ?int $hp): void
    {
        $s = $this->setup($lokasiId);
        if (! $s?->giliran_aktif) {
            return;
        }
        if (! $hp) {
            throw new \RuntimeException('Pilih dulu nomor HP ini.');
        }
        if ($s->giliran_hp !== $hp) {
            throw new \RuntimeException("Belum giliran HP $hp — sekarang giliran HP {$s->giliran_hp}.");
        }
    }

    /**
     * Majukan giliran ke HP berikutnya. $dariHp diisi saat maju otomatis sesudah memilih:
     * giliran hanya maju bila memang masih di HP itu (admin bisa sudah menekan Lanjut).
     */
    public function lanjut(int $lokasiId, ?int $dariHp = null): ?int
    {
        return DB::transaction(function () use ($lokasiId, $dariHp) {
            $s = PilihUnitSetup::query()->where('lokasi_id', $lokasiId)->lockForUpdate()->first();
            if (! $s?->giliran_aktif || ($dariHp !== null && $s->giliran_hp !== $dariHp)) {
                return $s?->giliran_hp;
            }
            $s->giliran_hp = $s->giliran_hp >= $s->jumlah_hp ? 1 : $s->giliran_hp + 1;
            $s->save();

            return $s->giliran_hp;
        });
    }

    public function ulang(int $lokasiId): void
    {
        PilihUnitSetup::query()->where('lokasi_id', $lokasiId)->update(['giliran_hp' => 1, 'updated_at' => now()]);
    }

    private function kunci(int $lokasiId, int $hp): string
    {
        return "pilih_unit:hp:$lokasiId:$hp";
    }
}
