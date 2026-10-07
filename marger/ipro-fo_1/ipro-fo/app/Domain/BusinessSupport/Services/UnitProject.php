<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Master\Unit\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unit mana yang termasuk sebuah Project kontraktor.
 *
 * SATU DEFINISI untuk seluruh layar. Dipakai detail Setup Project dan layar pemasangan unit;
 * bila keduanya menulis penyaringnya sendiri, suatu saat yang satu menawarkan unit yang tidak
 * muncul di yang lain — dan tidak ada yang tahu mana yang benar.
 *
 * ── KENAPA CLUSTER + TAHAP, BUKAN KODE PROJECT ──
 *
 * Master Sales hanya punya SATU project ("JRES — Java Residence"); yang membedakan satu
 * kumpulan unit dari yang lain adalah cluster dan tahapnya. Kode project kontraktor (C3T4)
 * tidak pernah ada padanannya di sana.
 *
 * Kolom Cluster menyimpan NAMA ("JAVA RESIDENCE KRIAN 3") sementara kode unit memakai KODE
 * ("JRK3-004-XIX-17"), jadi cluster diresolusi dulu untuk mendapatkan kodenya.
 *
 * ── TAHAP HANYA ADA DI DALAM KODE UNIT ──
 *
 * Cermin unit BO tidak punya kolom tahap; ia terbaca dari kode. Penyaringnya karena itu
 * awalan "KODE-TAHAP-", dan tahap dinormalkan ke tiga angka — operator mengetik "5"
 * sementara kodenya berbunyi "005".
 *
 * TAHAP KOSONG BERARTI SELURUH CLUSTER, bukan nol unit: paket infrastruktur dan site
 * instruction memang mencakup satu cluster penuh.
 */
class UnitProject
{
    /** Cluster kita untuk sebuah Project, atau null bila kolomnya belum diisi. */
    public function cluster(BsProject $project): ?Cluster
    {
        if (blank($project->cluster)) {
            return null;
        }

        // Nama dulu (itu yang disimpan layar Setup), kode sebagai cadangan bila kelak yang
        // tersimpan berubah jadi kode.
        return Cluster::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $project->cluster)])->first()
            ?? Cluster::whereRaw('LOWER(code) = ?', [mb_strtolower((string) $project->cluster)])->first();
    }

    /** Awalan kode unit untuk project ini — null bila cluster-nya belum diisi. */
    public function awalanKode(BsProject $project, ?Cluster $cluster = null): ?string
    {
        $cluster ??= $this->cluster($project);
        if ($cluster === null) {
            return null;
        }

        $tahap = trim((string) $project->tahap);

        return $cluster->code.'-'.($tahap !== '' ? str_pad($tahap, 3, '0', STR_PAD_LEFT).'-' : '');
    }

    /**
     * Query unit milik project ini.
     *
     * Memulangkan query yang PASTI KOSONG bila cluster-nya belum diisi — bukan seluruh unit.
     * Tanpa itu, project yang belum disetel akan tampak memiliki 1362 unit, dan biaya SPK-nya
     * dibagi ke seluruh perumahan.
     *
     * @return Builder<Unit>
     */
    public function query(BsProject $project): Builder
    {
        $cluster = $this->cluster($project);

        if ($cluster === null) {
            return Unit::query()->whereRaw('1 = 0');
        }

        return Unit::query()
            ->where('cluster_id', $cluster->id)
            ->where('unit_code', 'like', $this->awalanKode($project, $cluster).'%');
    }
}
