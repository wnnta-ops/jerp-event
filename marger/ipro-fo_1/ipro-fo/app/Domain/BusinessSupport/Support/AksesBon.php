<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Services\CostCenterPengguna;

/**
 * Siapa boleh melihat bon / kasbon SIAPA — satu aturan untuk daftar Bon Sementara dan
 * Outstanding Kasbon, supaya kedua layar tidak pernah berbeda pendapat.
 *
 * Yang melihat SEMUA karyawan:
 *   cashbook.advance.pay          Dept Finance — merekalah yang membayar & menyelesaikan.
 *   businesssupport.approval.act  Penyetuju — bon menunggu tanda tangannya.
 * Selain itu hanya bon MILIKNYA SENDIRI: yang ia ajukan (created_by), atau yang pemegangnya
 * dirinya (holder = nama karyawannya, persis atau "Nama — Jabatan").
 */
final class AksesBon
{
    public static function lihatSemua(?object $user): bool
    {
        return (bool) ($user?->can('cashbook.advance.pay') || $user?->can('businesssupport.approval.act'));
    }

    /**
     * Nama-nama pemegang (holder) yang boleh dilihat pengguna ini — persis seperti tersimpan,
     * karena Outstanding Kasbon mengelompokkan barisnya per holder.
     *
     * @return list<string>
     */
    public static function holderMilik(?object $user): array
    {
        $nama = app(CostCenterPengguna::class)->namaKaryawan($user);

        return BsCashAdvance::query()
            ->whereNotNull('holder')
            ->where(function ($w) use ($user, $nama) {
                // (int): null pada where() menjadi whereNull — akun tanpa id justru akan
                // melihat semua bon yang created_by-nya kosong. Nol tak cocok dengan id mana pun.
                $w->where('created_by', (int) $user?->id);
                if ($nama !== null) {
                    $w->orWhere('holder', $nama)->orWhere('holder', 'like', $nama.' — %');
                }
            })
            ->distinct()->pluck('holder')->map(fn ($h) => (string) $h)->values()->all();
    }
}
