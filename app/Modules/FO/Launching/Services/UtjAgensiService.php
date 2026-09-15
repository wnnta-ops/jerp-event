<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Support\Carbon;

/**
 * UTJ (uang tanda jadi) yang MASUK pada satu bulan, dikelompokkan per AGENSI.
 *
 * EMPAT KEPUTUSAN YANG MENENTUKAN ANGKANYA, ditulis di sini supaya tidak tersebar:
 *
 * 1. "UTJ MASUK" memakai definisi yang sudah ada — NupRegistration::isUtjPaid():
 *    xendit_status 'PAID' ATAU verif_status 'PAID'. DITAMBAH satu keadaan yang selama ini
 *    luput: pendaftaran yang disetujui MANUAL. Pada jalur itu uangnya benar-benar masuk tetapi
 *    xendit_status tetap PENDING dan verif_status menjadi APPROVED, sehingga tanpa penambahan
 *    ini UTJ-nya tidak pernah muncul di bulan mana pun — hilang diam-diam, bukan salah angka.
 *
 * 2. YANG SUDAH DIKEMBALIKAN TIDAK DIHITUNG. reject() dan refund() hanya mengubah verif_status;
 *    xendit_status tetap 'PAID'. Untuk peringkat agensi, uang yang sudah dikembalikan bukan
 *    prestasi. Konsekuensinya angka panel ini bisa LEBIH KECIL daripada Report NUP, dan itu
 *    disebutkan di keterangan panel supaya selisihnya tidak dikira kesalahan.
 *
 * 3. BULANNYA dari tanggal uang masuk: xendit_paid_at → tgl_payment → verified_at (untuk yang
 *    disetujui manual). BUKAN created_at — itu tanggal DAFTAR, dan pendaftaran akhir bulan yang
 *    dibayar bulan berikutnya akan jatuh di bulan yang salah.
 *
 * 4. HANYA jenis 'NUP'. Baris 'BOOKED' ikut duduk di tabel yang sama dengan utj_amount 0 dan
 *    verif_status langsung APPROVED — tanpa penyaring ini, booking fee ikut terhitung sebagai
 *    UTJ begitu keadaan "disetujui manual" ikut dihitung.
 *
 * Yang dihitung adalah BANYAKNYA pendaftaran, bukan jumlah rupiahnya.
 */
final class UtjAgensiService
{
    /** Pendaftaran yang dihitung — 'BOOKED' bukan UTJ. */
    public const JENIS = 'NUP';

    /** Kelompok untuk UTJ tanpa agensi (mandiri/walk-in) — ditampilkan, bukan dibuang. */
    public const TANPA_AGENSI = '(Tanpa Agensi)';

    /** Status yang berarti uangnya sudah dikembalikan. */
    public const DIKEMBALIKAN = [NupRegistration::VERIF_REJECTED, NupRegistration::VERIF_REFUNDED];

    /**
     * @return array<int,array{agensi: string, utj: int}> diurut dari terbanyak
     */
    public function perAgensi(int $tahun, int $bulan): array
    {
        $awal  = Carbon::create($tahun, $bulan, 1)->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();

        $rows = $this->dasar()->get([
            'fo_nup_registrations.xendit_paid_at',
            'fo_nup_registrations.tgl_payment',
            'fo_nup_registrations.verified_at',
            'fo_agents.agency_name',
        ]);

        $hitung = [];
        foreach ($rows as $r) {
            $tgl = $this->tanggalMasuk($r);
            if ($tgl === null || ! $tgl->between($awal, $akhir)) {
                continue;
            }
            $agensi = trim((string) ($r->agency_name ?? ''));
            $agensi = $agensi === '' ? self::TANPA_AGENSI : $agensi;
            $hitung[$agensi] = ($hitung[$agensi] ?? 0) + 1;
        }

        // Terbanyak dulu; yang sama banyak diurut menurut nama supaya urutannya tidak
        // berubah-ubah antar pemuatan halaman.
        uksort($hitung, fn ($a, $b) => ($hitung[$b] <=> $hitung[$a]) ?: strcmp($a, $b));

        return array_map(fn ($a) => ['agensi' => $a, 'utj' => $hitung[$a]], array_keys($hitung));
    }

    /** Bulan paling awal yang punya UTJ masuk — batas bawah daftar periode pada filter. */
    public function bulanPertama(): ?Carbon
    {
        $awal = null;
        foreach ($this->dasar()->get(['fo_nup_registrations.xendit_paid_at', 'fo_nup_registrations.tgl_payment', 'fo_nup_registrations.verified_at']) as $r) {
            $t = $this->tanggalMasuk($r);
            if ($t !== null && ($awal === null || $t->lt($awal))) {
                $awal = $t;
            }
        }

        return $awal?->startOfMonth();
    }

    /**
     * Pendaftaran yang UTJ-nya dianggap masuk dan belum dikembalikan.
     *
     * leftJoin ke fo_agents, bukan join: sebagian besar pendaftaran TIDAK punya agent_id —
     * kolom itu hanya terisi bila teks sales yang diketik pendaftar persis sama dengan label
     * master, dan form admin tidak punya isian agen sama sekali. Dengan join biasa, seluruh UTJ
     * mandiri hilang dan totalnya tidak akan pernah sama dengan Report NUP.
     */
    private function dasar()
    {
        return NupRegistration::query()
            ->where('fo_nup_registrations.jenis', self::JENIS)
            ->whereNotIn('fo_nup_registrations.verif_status', self::DIKEMBALIKAN)
            ->where(fn ($q) => $q
                ->where('fo_nup_registrations.xendit_status', 'PAID')
                ->orWhere('fo_nup_registrations.verif_status', NupRegistration::VERIF_PAID)
                ->orWhere(fn ($m) => $m
                    ->where('fo_nup_registrations.is_manual_approve', true)
                    ->where('fo_nup_registrations.verif_status', NupRegistration::VERIF_APPROVED)))
            ->leftJoin('fo_agents', 'fo_agents.id', '=', 'fo_nup_registrations.agent_id');
    }

    /** Tanggal uang masuk; null bila tidak ada satu pun penanda waktunya. */
    private function tanggalMasuk(object $r): ?Carbon
    {
        foreach ([$r->xendit_paid_at ?? null, $r->tgl_payment ?? null, $r->verified_at ?? null] as $calon) {
            if (is_string($calon) && trim($calon) !== '') {
                try {
                    return Carbon::parse($calon)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }
            }
            if ($calon instanceof Carbon) {
                return $calon->copy()->startOfDay();
            }
        }

        return null;
    }
}
