<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsProjectMuk;
use App\Domain\Foundation\Tenant\Models\Cluster;
use Illuminate\Support\Collection;

/**
 * Usulan baris Setup Project dari daftar "proyek" MUK yang belum dipetakan.
 *
 * MENGUSULKAN, BUKAN MEMUTUSKAN. Seluruh hasil di sini wajib melewati layar dan ditekan
 * orang. Alasannya terbaca dari datanya sendiri — 94 nama proyek MUK diurai dan hasilnya:
 *
 *     Cluster DAN Tahap terbaca   : 42  (45%)
 *     Cluster saja, tanpa tahap   : 42  (45%)
 *     Tidak terbaca sama sekali   : 10  (10%)
 *
 * Yang 42 tanpa tahap BUKAN data yang kurang: "INFRA JAVA RESIDENCE KRIAN CLUSTER 2" dan
 * "SITE INSTRUCTION CLUSTER 1 INFRA" memang mencakup satu cluster penuh. Memaksakan tahap
 * ke situ berarti mengarang, dan biayanya menempel ke tahap yang salah. Karena itu tahap
 * dibiarkan kosong, dan kosong di layar detail sudah berarti "seluruh unit di cluster itu".
 *
 * Yang 10 memang bukan bagian cluster mana pun — Java Center, Indomaret, kantor MUP.
 * Diusulkan tanpa cluster dan ditampilkan terpisah, bukan dipaksa masuk ke salah satu.
 *
 * ── KENAPA CLUSTER DIRESOLUSI DARI KODE, BUKAN DAFTAR TETAP ──
 * "CLUSTER 3" dicocokkan ke cluster kita yang kodenya berakhiran 3 (JRK3). Diambil dari
 * master, bukan ditulis sebagai daftar di sini: begitu kelak ada JRK4 atau JRS6, ia ikut
 * terbaca tanpa menyentuh kode. Daftar tetap akan diam-diam gagal pada cluster yang belum
 * ada waktu baris ini ditulis.
 */
class UsulProjectMuk
{
    /** Angka Romawi yang dipakai pada penamaan tahap MUK. */
    private const ROMAWI = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];

    /**
     * Usulan, dikelompokkan per (cluster, tahap).
     *
     * @return array<int, array<string, mixed>>
     */
    public function untukYangBelumDipetakan(): array
    {
        $sudah = BsProjectMuk::pluck('muk_proyek_kode')->all();

        $proyek = BsMukContract::query()
            ->selectRaw('proyek_kode, MAX(proyek_nama) AS proyek_nama, COUNT(*) AS jml')
            ->whereNotNull('proyek_kode')->where('proyek_kode', '!=', '')
            ->whereNotIn('proyek_kode', $sudah)
            ->groupBy('proyek_kode')->orderBy('proyek_kode')->get();

        $cluster = Cluster::orderBy('code')->get(['id', 'code', 'name']);

        $kelompok = [];
        foreach ($proyek as $p) {
            [$noCluster, $noTahap] = $this->urai((string) $p->proyek_nama);
            $c = $noCluster !== null ? $this->clusterUntuk($noCluster, $cluster) : null;

            // Kunci kelompok memakai nomor mentah dari nama, bukan id cluster hasil
            // resolusi: paket bercluster-3 tetap satu kelompok walaupun JRK3 belum ada di
            // master, sehingga operator melihatnya sebagai satu keputusan.
            $kunci = ($noCluster ?? '-').'|'.($noTahap ?? '-');

            $kelompok[$kunci] ??= [
                'kunci' => $kunci,
                'cluster_no' => $noCluster,
                'tahap_no' => $noTahap,
                'cluster_id' => $c?->id,
                'cluster_kode' => $c?->code,
                'cluster_nama' => $c?->name,
                // Kode project diusulkan singkat & terbaca: C3T4, atau C3 bila tanpa tahap.
                'kode_usul' => $noCluster !== null
                    ? 'C'.$noCluster.($noTahap !== null ? 'T'.$noTahap : '')
                    : null,
                'nama_usul' => $c !== null
                    ? trim($c->name.($noTahap !== null ? ' TAHAP '.$noTahap : ''))
                    : null,
                'paket' => [],
            ];

            $kelompok[$kunci]['paket'][] = [
                'kode' => $p->proyek_kode,
                'nama' => $p->proyek_nama,
                'jml_kontrak' => (int) $p->jml,
            ];
        }

        // Yang bercluster lebih dulu, lalu yang tanpa cluster — itu urutan kerja operator:
        // yang jelas dibereskan dulu, yang perlu dipikirkan belakangan.
        return collect($kelompok)->sortBy([
            fn ($g) => $g['cluster_no'] === null ? 1 : 0,
            fn ($g) => $g['cluster_no'] ?? 0,
            fn ($g) => $g['tahap_no'] ?? 0,
        ])->values()->all();
    }

    /**
     * Baca nomor cluster & tahap dari nama proyek MUK.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function urai(string $nama): array
    {
        $n = mb_strtoupper($nama);

        // JRKC/JKRC/JRSC/CLUTER ikut dikenali: ketiganya salah ketik yang benar-benar ada
        // di data MUK, dan membiarkannya berarti paket itu jatuh ke "tidak terbaca".
        $cluster = null;
        foreach (['CLUSTER\s*(\d+)', 'JRKC\s*(\d+)', 'JKRC\s*(\d+)', 'JRSC\s*(\d+)', 'CLUTER\s*(\d+)'] as $pola) {
            if (preg_match('/'.$pola.'/u', $n, $m) === 1) {
                $cluster = (int) $m[1];
                break;
            }
        }

        $tahap = null;
        if (preg_match('/TAHAP\s*([IVX]+|\d+)/u', $n, $m) === 1) {
            $tahap = ctype_digit($m[1]) ? (int) $m[1] : (self::ROMAWI[$m[1]] ?? null);
        } elseif (preg_match('/PHASE\s*(\d+)/u', $n, $m) === 1) {
            $tahap = (int) $m[1];
        }

        return [$cluster, $tahap];
    }

    /**
     * Cluster kita untuk sebuah nomor cluster MUK — dicocokkan lewat akhiran kodenya.
     *
     * @param  Collection<int, Cluster>  $daftar
     */
    private function clusterUntuk(int $nomor, Collection $daftar): ?Cluster
    {
        return $daftar->first(
            fn (Cluster $c) => preg_match('/^[A-Z]+0*'.$nomor.'$/i', (string) $c->code) === 1
        );
    }
}
