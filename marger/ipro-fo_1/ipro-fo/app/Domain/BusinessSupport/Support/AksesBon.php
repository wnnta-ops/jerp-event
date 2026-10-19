<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Services\CostCenterPengguna;
use Illuminate\Database\Eloquent\Builder;

/**
 * Siapa boleh melihat bon / kasbon SIAPA — satu aturan untuk daftar Bon Sementara dan
 * Outstanding Kasbon, supaya kedua layar tidak pernah berbeda pendapat.
 *
 * Yang melihat SEMUA karyawan:
 *   cashbook.advance.pay          Dept Finance — merekalah yang membayar & menyelesaikan.
 *   businesssupport.approval.act  Penyetuju — bon menunggu tanda tangannya.
 * Selain itu bon MILIKNYA SENDIRI — yang ia ajukan (created_by), atau yang pemegangnya dirinya
 * (holder = nama karyawannya, persis atau "Nama — Jabatan") — DITAMBAH bon SATU TIMNYA: bon yang
 * cost center-nya sama dengan cost center karyawan yang tertaut ke akunnya (19 Okt 2026: anggota
 * tim ikut tahu & boleh menindaklanjuti pengajuan rekannya). Akun yang belum tertaut ke karyawan,
 * atau karyawannya belum ber-cost center, tetap hanya melihat miliknya sendiri.
 */
final class AksesBon
{
    public static function lihatSemua(?object $user): bool
    {
        return (bool) ($user?->can('cashbook.advance.pay') || $user?->can('businesssupport.approval.act'));
    }

    /** Batasi query bon ke yang boleh dilihat pengguna ini (tanpa batas bagi Finance/penyetuju). */
    public static function batasi(Builder $q, ?object $user): Builder
    {
        if (self::lihatSemua($user)) {
            return $q;
        }

        $cc = app(CostCenterPengguna::class);
        $nama = $cc->namaKaryawan($user);
        $tim = $cc->idUntuk($user);

        return $q->where(function (Builder $w) use ($user, $nama, $tim) {
            // (int): null pada where() menjadi whereNull — akun tanpa id justru akan melihat
            // semua bon yang created_by-nya kosong. Nol tak cocok dengan id mana pun.
            $w->where('created_by', (int) $user?->id);
            if ($nama !== null) {
                $w->orWhere('holder', $nama)->orWhere('holder', 'like', $nama.' — %');
            }
            if ($tim !== null) {
                $w->orWhere('cost_center_id', $tim);
            }
        });
    }

    /** Bolehkah pengguna ini membuka (lihat, cetak, realisasi) satu bon? Aturan sama dengan daftar. */
    public static function bolehBuka(BsCashAdvance $advance, ?object $user): bool
    {
        return self::batasi(BsCashAdvance::query()->whereKey($advance->getKey()), $user)->exists();
    }

    /**
     * Nama-nama pemegang (holder) yang boleh dilihat pengguna ini — persis seperti tersimpan,
     * karena Outstanding Kasbon mengelompokkan barisnya per holder.
     *
     * @return list<string>
     */
    public static function holderMilik(?object $user): array
    {
        return self::batasi(BsCashAdvance::query()->whereNotNull('holder'), $user)
            ->distinct()->pluck('holder')->map(fn ($h) => (string) $h)->values()->all();
    }
}
