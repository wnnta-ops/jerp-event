<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Tutup Buku — kunci periode fiskal (cegah posting mundur) & jurnal penutup tahunan
 * (nol-kan akun nominal Pendapatan/Beban → Laba Ditahan).
 */
final class PeriodClosingService
{
    public function __construct(private readonly JournalService $journal) {}

    /** Kunci satu periode bulanan (status=closed). */
    public function closePeriod(int $year, int $month): BsFiscalPeriod
    {
        $this->pastikanSemuaApproved($year, $month);

        $period = $this->journal->ensurePeriod($year, $month);
        $period->update(['status' => 'closed', 'closed_at' => now()]);

        return $period;
    }

    /**
     * Periode tidak boleh dikunci selama masih ada jurnal yang belum Approved.
     *
     * Menutup periode berarti mengunci jurnalnya dari pengeditan. Jurnal Baru (draft) dan
     * Jurnal Final belum efektif di General Ledger — angkanya belum masuk laporan mana pun.
     * Mengunci periode saat keduanya masih ada akan membekukan mereka dalam keadaan setengah
     * jadi: tidak terhitung di laporan, tetapi juga tidak bisa lagi di-approve maupun
     * diperbaiki tanpa membatalkan closing-nya lebih dulu. Laporan periode itu lalu terlihat
     * sah dan final padahal ada transaksi yang tertinggal di luar hitungan.
     *
     * Diperiksa di sini, BUKAN di controller, supaya seluruh pintu penutupan terjaga sama —
     * Closing Fiskal, Late Closing, dan penguncian 12 bulan pada Tutup Buku tahunan. Penjaga
     * yang bisa dilewati dengan menekan tombol lain bukan penjaga.
     */
    private function pastikanSemuaApproved(int $year, int $month): void
    {
        $jumlah = BsJournal::query()
            ->where('period_year', $year)->where('period_month', $month)
            ->whereIn('status', ['draft', 'final'])
            ->selectRaw('status, COUNT(*) AS jml')
            ->groupBy('status')->pluck('jml', 'status');

        $baru  = (int) ($jumlah['draft'] ?? 0);
        $final = (int) ($jumlah['final'] ?? 0);
        if ($baru === 0 && $final === 0) {
            return;
        }

        // Jumlahnya disebutkan supaya operator tahu seberapa besar pekerjaannya, dan yang
        // bernilai nol tidak ikut disebut — "0 Jurnal Final" hanya menambah kebingungan.
        $bagian = [];
        if ($baru > 0) {
            $bagian[] = "{$baru} Jurnal Baru";
        }
        if ($final > 0) {
            $bagian[] = "{$final} Jurnal Final";
        }

        throw new BusinessRuleException(
            "Periode {$month}/{$year} belum bisa ditutup: masih ada ".implode(' dan ', $bagian)
            .'. Semuanya harus menjadi Jurnal Approved lebih dulu agar closing bisa dilakukan.',
            errorCode: 'PERIOD_HAS_UNAPPROVED',
        );
    }

    /**
     * Berapa jurnal yang belum Approved pada tiap bulan sebuah tahun.
     *
     * Dipakai layar Closing Fiskal untuk mengatakannya SEBELUM tombolnya ditekan — penolakan
     * yang baru muncul sesudah diklik memaksa operator menebak bulan mana yang bermasalah.
     *
     * @return array<int,array{baru:int,final:int}> berindeks bulan 1..12
     */
    public function belumApprovedPerBulan(int $year): array
    {
        $rows = BsJournal::query()
            ->where('period_year', $year)
            ->whereIn('status', ['draft', 'final'])
            ->selectRaw('period_month AS bulan, status, COUNT(*) AS jml')
            ->groupBy('period_month', 'status')->get();

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = [
                'baru'  => (int) ($rows->first(fn ($r) => (int) $r->bulan === $m && $r->status === 'draft')->jml ?? 0),
                'final' => (int) ($rows->first(fn ($r) => (int) $r->bulan === $m && $r->status === 'final')->jml ?? 0),
            ];
        }

        return $out;
    }

    /** Buka kembali periode (status=open). */
    public function reopenPeriod(int $year, int $month): BsFiscalPeriod
    {
        $period = $this->journal->ensurePeriod($year, $month);
        $period->update(['status' => 'open', 'closed_at' => null]);

        return $period;
    }

    /**
     * Tutup Buku Tahunan — jurnal penutup: nol-kan Pendapatan & Beban ke Laba Ditahan, lalu kunci 12 bulan.
     */
    public function closeYear(int $year, ?int $userId = null): ?BsJournal
    {
        $reference = "CLOSING/{$year}";
        if (BsJournal::withTrashed()->where('reference', $reference)->where('source', 'closing')->exists()) {
            throw new BusinessRuleException("Tahun {$year} sudah ditutup (jurnal penutup {$reference} ada).", errorCode: 'YEAR_CLOSED');
        }

        $retained = BsAccount::where('code', config('business_support.gl_accounts.retained_earnings'))->value('id');
        if (! $retained) {
            throw new BusinessRuleException('Akun Laba Ditahan belum diset.', errorCode: 'NO_RETAINED');
        }

        return DB::transaction(function () use ($year, $userId, $reference, $retained): ?BsJournal {
            // Saldo akun nominal (income/expense) untuk tahun berjalan dari jurnal posted.
            $balances = DB::table('bs_journal_lines as jl')
                ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
                ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('j.status', 'posted')->where('j.period_year', $year)
                ->whereIn('a.type', ['income', 'expense'])
                ->groupBy('jl.account_id', 'a.type')
                ->selectRaw('jl.account_id, a.type, COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c')
                ->get();

            $jl = [];
            $net = 0.0; // laba (+) / rugi (−)
            foreach ($balances as $b) {
                if ($b->type === 'income') {
                    $bal = round((float) $b->c - (float) $b->d, 2); // saldo kredit
                    if (abs($bal) < 0.01) {
                        continue;
                    }
                    // Nol-kan: debit pendapatan sebesar saldo kreditnya.
                    $jl[] = ['account_id' => $b->account_id, 'debit' => $bal, 'credit' => 0, 'description' => 'Tutup pendapatan'];
                    $net += $bal;
                } else {
                    $bal = round((float) $b->d - (float) $b->c, 2); // saldo debit
                    if (abs($bal) < 0.01) {
                        continue;
                    }
                    // Nol-kan: kredit beban sebesar saldo debitnya.
                    $jl[] = ['account_id' => $b->account_id, 'debit' => 0, 'credit' => $bal, 'description' => 'Tutup beban'];
                    $net -= $bal;
                }
            }

            $journal = null;
            if (! empty($jl) && abs($net) >= 0.01) {
                // Saldo lawan ke Laba Ditahan: laba → kredit, rugi → debit.
                if ($net > 0) {
                    $jl[] = ['account_id' => $retained, 'debit' => 0, 'credit' => round($net, 2), 'description' => 'Laba tahun '.$year.' ke Laba Ditahan'];
                } else {
                    $jl[] = ['account_id' => $retained, 'debit' => round(-$net, 2), 'credit' => 0, 'description' => 'Rugi tahun '.$year.' ke Laba Ditahan'];
                }

                $journal = $this->journal->post([
                    'journal_date' => "{$year}-12-31",
                    'source'       => 'closing',
                    'reference'    => $reference,
                    'description'  => "Jurnal Penutup tahun {$year} — Pendapatan & Beban → Laba Ditahan",
                    'created_by'   => $userId,
                ], $jl);
            }

            // Kunci seluruh 12 bulan.
            for ($m = 1; $m <= 12; $m++) {
                $this->closePeriod($year, $m);
            }

            return $journal;
        });
    }
}
