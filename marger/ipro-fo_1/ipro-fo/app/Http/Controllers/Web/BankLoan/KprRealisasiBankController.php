<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * REALISASI KPR PER BANK — unit akad per cluster per bank, kumulatif.
 *
 * Dihitung dari data akad, bukan diunggah: angkanya selalu mutakhir dan definisinya SAMA PERSIS
 * dengan layar Realisasi vs Budget. Dua laporan realisasi yang menghitung "akad" dengan cara
 * berbeda akan saling membantah, dan yang membacanya tidak punya cara menebak mana yang benar.
 *
 * Ketiga aturan hitungnya sama dengan KprTargetRealisasiController — sengaja diulang di sini
 * supaya perubahannya tidak pernah hanya separuh:
 *
 *   1. APLIKASI TERBARU PER BOOKING. Satu booking bisa punya beberapa aplikasi KPR (ganti bank);
 *      menghitung seluruh barisnya membuat satu unit terhitung dua kali — dan, khusus di layar
 *      ini, unit yang sama akan muncul di DUA kolom bank sekaligus.
 *   2. AKAD YANG BATAL BUKAN REALISASI (rejected/cancelled dikeluarkan).
 *   3. TENANT DISARING EKSPLISIT. DB::table melewati global scope tenant.
 *
 * "Kumulatif" berarti seluruh tahun, tanpa saringan periode — itulah yang ditanyakan komposisi
 * bank: berapa banyak KPR yang pernah diakadkan di tiap bank, bukan berapa bulan ini.
 */
class KprRealisasiBankController extends Controller
{
    /** @return array<int, string> */
    private static function statusSudahAkad(): array
    {
        return [KprStatus::AkadDone->value, KprStatus::Disbursed->value, KprStatus::Active->value];
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $baris = DB::table('kpr_applications as a')
            ->join('kpr_akads as k', 'k.kpr_application_id', '=', 'a.id')
            ->leftJoin('bookings as b', 'b.id', '=', 'a.booking_id')
            ->leftJoin('units as u', 'u.id', '=', 'b.unit_id')
            ->leftJoin('clusters as c', 'c.id', '=', 'u.cluster_id')
            ->leftJoin('bank_partners as p', 'p.id', '=', 'a.bank_partner_id')
            ->when(tenant_id() !== null, fn ($q) => $q->where('a.tenant_id', tenant_id()))
            ->whereIn('a.status', self::statusSudahAkad())
            ->whereNotNull('k.akad_at')
            ->whereRaw('a.id = (select max(a2.id) from kpr_applications a2 where a2.booking_id = a.booking_id)')
            ->selectRaw('COALESCE(c.name, ?) as cluster, COALESCE(p.name, ?) as bank, COUNT(DISTINCT a.booking_id) as unit', ['(Tanpa Cluster)', '(Tanpa Bank)'])
            ->groupBy('cluster', 'bank')
            ->get();

        /* Kolom bank dibentuk dari data yang ADA, bukan dari daftar bank yang dipatok di kode.
           Bank baru karena itu muncul sendiri; bank yang belum pernah dipakai tidak menyisakan
           kolom kosong yang membuat tabelnya melebar tanpa isi. */
        $bank = $baris->pluck('bank')->unique()->sort()->values()->all();
        $cluster = $baris->pluck('cluster')->unique()->sort()->values()->all();

        $peta = [];
        foreach ($baris as $b) {
            $peta[$b->cluster][$b->bank] = (int) $b->unit;
        }

        $rows = [];
        foreach ($cluster as $c) {
            $sel = [];
            $total = 0;
            foreach ($bank as $nb) {
                $v = (int) ($peta[$c][$nb] ?? 0);
                $sel[$nb] = $v;
                $total += $v;
            }
            $rows[] = ['cluster' => $c, 'total' => $total, 'bank' => $sel];
        }

        $totalBank = [];
        foreach ($bank as $nb) {
            $totalBank[$nb] = array_sum(array_map(fn ($r) => $r['bank'][$nb], $rows));
        }
        $grand = array_sum($totalBank);

        /* Komposisi bank diurutkan MENURUN — pertanyaan yang dijawab kartu ini adalah "bank mana
           yang paling banyak", dan urutan abjad menyembunyikan jawabannya. */
        $komposisi = [];
        foreach ($totalBank as $nb => $v) {
            $komposisi[] = [
                'bank'  => $nb,
                'unit'  => $v,
                // Persentase dibulatkan satu desimal: bank dengan 7 dari 915 akad menjadi 0,8% —
                // membulatkannya ke bilangan bulat mengubahnya menjadi 1% atau 0%, dan 0% terbaca
                // sebagai "tidak ada sama sekali".
                'persen' => $grand > 0 ? round($v / $grand * 100, 1) : 0.0,
            ];
        }
        usort($komposisi, fn ($x, $y) => $y['unit'] <=> $x['unit']);

        return Inertia::render('BankLoan/Database/RealisasiPerBank', [
            'banks'     => $bank,
            'rows'      => $rows,
            'totalBank' => $totalBank,
            'grand'     => $grand,
            'komposisi' => $komposisi,
        ]);
    }
}
