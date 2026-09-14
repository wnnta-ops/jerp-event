<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsOpeningConfirmation;
use App\Domain\BusinessSupport\Services\PeriodClosingService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Support\Import\TabelImpor;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tutup Buku — kunci periode bulanan & jurnal penutup tahunan (L/R → Laba Ditahan).
 */
class PeriodClosingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.closing.view'), 403);
        $year = (int) ($request->integer('year') ?: now()->year);

        $statuses = BsFiscalPeriod::where('year', $year)->pluck('status', 'month');
        $closedAt = BsFiscalPeriod::where('year', $year)->pluck('closed_at', 'month');

        // P/L per bulan (pendapatan − beban) dari jurnal posted.
        $rows = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->where('j.period_year', $year)
            ->whereIn('a.type', ['income', 'expense'])
            ->groupBy('j.period_month', 'a.type')
            ->selectRaw('j.period_month month, a.type, COALESCE(SUM(jl.credit)-SUM(jl.debit),0) net')
            ->get();

        // Jurnal yang belum Approved per bulan. Dikirim ke layar supaya penghalang closing
        // terbaca SEBELUM tombolnya ditekan — penolakan yang baru muncul sesudah diklik
        // memaksa operator menebak bulan mana yang bermasalah dan berapa banyak sisanya.
        $belum = app(PeriodClosingService::class)->belumApprovedPerBulan($year);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $income  = (float) ($rows->where('month', $m)->where('type', 'income')->first()->net ?? 0);
            $expense = -1 * (float) ($rows->where('month', $m)->where('type', 'expense')->first()->net ?? 0); // beban: debit-credit
            $months[] = [
                'month' => $m, 'income' => round($income, 2), 'expense' => round($expense, 2),
                'profit' => round($income - $expense, 2),
                'status' => $statuses[$m] ?? 'open', 'closed_at' => $closedAt[$m] ?? null,
                'baru'   => $belum[$m]['baru'],
                'final'  => $belum[$m]['final'],
            ];
        }

        $ytdIncome  = round(array_sum(array_column($months, 'income')), 2);
        $ytdExpense = round(array_sum(array_column($months, 'expense')), 2);
        $closingJournal = BsJournal::where('reference', "CLOSING/{$year}")->where('source', 'closing')->first();

        return Inertia::render('BusinessSupport/Gl/TutupBuku', [
            'year'    => $year,
            'months'  => $months,
            'ytd'     => ['income' => $ytdIncome, 'expense' => $ytdExpense, 'profit' => round($ytdIncome - $ytdExpense, 2)],
            'yearClosed' => (bool) $closingJournal,
            'closingJournalId' => $closingJournal?->id,
            'allClosed'  => collect($months)->every(fn ($m) => $m['status'] === 'closed'),
        ]);
    }

    /**
     * Neraca Awal tahun berjalan — saldo carry-forward akun permanen (aset/kewajiban/ekuitas)
     * per 1 Januari {year} = saldo kumulatif s/d 31 Des {year-1}. Termasuk Laba Ditahan setelah tutup buku.
     */
    public function neracaAwal(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.saldo-awal.view'), 403);
        $year   = (int) ($request->integer('year') ?: now()->year);
        $cutoff = sprintf('%04d-01-01', $year); // mutasi < tanggal ini = s/d 31 Des tahun sebelumnya

        $balances = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<', $cutoff)
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->get();

        $section = fn (string $type, bool $debitNormal) => $balances->where('type', $type)
            ->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'amount' => round($debitNormal ? (float) $x->net : -1 * (float) $x->net, 2)])
            ->filter(fn ($x) => abs($x['amount']) > 0.001)->sortBy('code')->values();

        $assets = $section('asset', true);
        $liabilities = $section('liability', false);
        $equity = $section('equity', false);
        // Laba/(rugi) yang belum dijurnal-tutup (akun nominal s/d cutoff). 0 bila tahun lalu sudah ditutup.
        $unclosed = round((float) $balances->whereIn('type', ['income', 'expense'])->sum('net') * -1, 2); // (credit-debit)

        $totalAssets = round($assets->sum('amount'), 2);
        $totalLiab   = round($liabilities->sum('amount'), 2);
        $totalEquity = round($equity->sum('amount') + $unclosed, 2);
        $priorClosed = BsJournal::where('reference', 'CLOSING/'.($year - 1))->where('source', 'closing')->exists();

        return Inertia::render('BusinessSupport/Gl/NeracaAwal', [
            'year' => $year,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'unclosed' => $unclosed,
            'totals' => [
                'assets' => $totalAssets, 'liabilities' => $totalLiab, 'equity' => $totalEquity,
                'liab_equity' => round($totalLiab + $totalEquity, 2),
                'balanced' => abs($totalAssets - ($totalLiab + $totalEquity)) < 0.01,
            ],
            'priorClosed' => $priorClosed,
        ]);
    }

    /** Jurnal Saldo Awal (preview) — Neraca Awal dalam bentuk jurnal Dr/Cr per akun. Tidak di-posting. */
    public function saldoAwalJurnal(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.saldo-awal.view'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);

        return Inertia::render('BusinessSupport/Gl/SaldoAwalJurnal', [
            'year'   => $year,
            'lines'  => $lines,
            'totals' => [
                'debit'  => round(array_sum(array_column($lines, 'debit')), 2),
                'credit' => round(array_sum(array_column($lines, 'credit')), 2),
            ],
            'priorClosed' => BsJournal::where('reference', 'CLOSING/'.($year - 1))->where('source', 'closing')->exists(),
        ]);
    }

    public function saldoAwalCsv(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('gl.saldo-awal.view'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);

        return response()->streamDownload(function () use ($lines, $year): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['TANGGAL', 'KODE_AKUN', 'NAMA_AKUN', 'DEBIT', 'KREDIT', 'KETERANGAN']);
            foreach ($lines as $l) {
                fputcsv($out, [sprintf('%04d-01-01', $year), $l['code'], $l['name'], $l['debit'], $l['credit'], 'Saldo Awal '.$year]);
            }
            fclose($out);
        }, "jurnal-saldo-awal-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Kolom template Import Saldo Awal — urutannya tetap; pencocokan saat import toleran. */
    public const IMPORT_HEADER = ['KODE_AKUN', 'NAMA_AKUN', 'DEBIT', 'KREDIT', 'KETERANGAN'];

    /**
     * Template Import Saldo Awal (.xlsx sungguhan).
     *
     * Kolomnya sengaja sama dengan hasil "Unduh CSV Jurnal" halaman ini, supaya berkas yang
     * diunduh bisa disunting lalu diunggah kembali tanpa menata ulang kolomnya.
     */
    public function saldoAwalTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->can('gl.saldo-awal.create'), 403);

        // Contohnya memakai akun NYATA, supaya orang langsung melihat bentuk kode akun yang benar.
        $debit  = BsAccount::where('is_postable', true)->where('type', 'asset')->orderBy('code')->first();
        $kredit = BsAccount::where('is_postable', true)->where('type', 'equity')->orderBy('code')->first();

        $bin = SimpleXlsx::toString([
            self::IMPORT_HEADER,
            [$debit?->code ?? '10101010101', $debit?->name ?? 'Kas', '1000000', '0', 'CONTOH — hapus baris ini sebelum mengisi data Anda'],
            [$kredit?->code ?? '30101010101', $kredit?->name ?? 'Modal', '0', '1000000', 'CONTOH — hapus baris ini sebelum mengisi data Anda'],
        ]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Import-Saldo-Awal.xlsx"',
        ]);
    }

    /**
     * IMPORT SALDO AWAL — satu jurnal saldo awal untuk satu tahun.
     *
     * TANGGALNYA 31 DESEMBER TAHUN SEBELUMNYA, bukan 1 Januari. Halaman ini membaca jurnal
     * bertanggal SEBELUM 1 Januari (lihat openingJournalLines), jadi jurnal bertanggal 1 Januari
     * tidak akan tampil di halaman tahun yang baru saja diimpor — dan kolom Saldo Awal buku besar
     * tahun itu tetap nol, sebab angkanya terbaca sebagai transaksi tanggal 1 Januari, bukan
     * saldo pembuka.
     *
     * STATUSNYA LANGSUNG 'posted'. openingJournalLines hanya menghitung jurnal 'posted', sedangkan
     * JournalService::post secara bawaan membuat 'final' — tanpa penegasan ini, saldo yang baru
     * diimpor tidak muncul di mana pun dan fiturnya tampak tidak bekerja.
     *
     * DITOLAK BILA TAHUN ITU SUDAH PUNYA JURNAL SALDO AWAL. Mengimpor dua kali melipatgandakan
     * seluruh saldo pembuka, dan jumlahnya tetap balance — tidak ada satu galat pun yang memberi
     * tahu; selisihnya baru ketahuan saat neracanya dibandingkan dengan sistem lama.
     */
    public function saldoAwalImport(Request $request, \App\Domain\BusinessSupport\Services\JournalService $journal): RedirectResponse
    {
        abort_unless($request->user()?->can('gl.saldo-awal.create'), 403);
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'file' => ['required', 'file', 'max:8192'],
        ]);

        $year = (int) $request->integer('year');
        $ref  = 'SALDOAWAL/'.$year;

        if (BsJournal::where('reference', $ref)->where('source', 'saldo_awal')->exists()) {
            return back()->with('error', "Saldo Awal {$year} sudah pernah diimpor ({$ref}). Hapus jurnalnya dulu bila memang hendak diganti — mengimpor ulang akan melipatgandakan seluruh saldo pembukanya.");
        }

        /* .xls TIDAK diterima. Berkas BIFF lama tidak bisa dibaca satu pun mekanisme di aplikasi
           ini; menerimanya hanya menghasilkan galat yang membingungkan ("kolom tidak ditemukan")
           atas berkas yang sebenarnya benar isinya. */
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            return back()->with('error', 'Format file harus .xlsx atau .csv. Berkas .xls lama tidak didukung — simpan ulang sebagai .xlsx. Unduh template untuk format yang benar.');
        }

        try {
            $rows = TabelImpor::baca($request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('error', 'File tidak dapat dibaca: '.$e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau hanya berisi baris header.');
        }

        $header = TabelImpor::header(array_shift($rows));
        $iKode  = TabelImpor::kolom($header, ['KODE_AKUN', 'KODE AKUN', 'KODE', 'AKUN'], ['NAMA']);
        $iDebit = TabelImpor::kolom($header, ['DEBIT', 'DEBET']);
        $iKred  = TabelImpor::kolom($header, ['KREDIT', 'CREDIT']);
        $iKet   = TabelImpor::kolom($header, ['KETERANGAN', 'DESKRIPSI', 'CATATAN']);

        $kurang = [];
        if ($iKode === null) { $kurang[] = 'KODE_AKUN'; }
        if ($iDebit === null) { $kurang[] = 'DEBIT'; }
        if ($iKred === null) { $kurang[] = 'KREDIT'; }
        if ($kurang) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: '.implode(', ', $kurang).'. Unduh template untuk format yang benar.');
        }

        $akun = BsAccount::where('is_postable', true)->get(['id', 'code'])
            ->keyBy(fn ($a) => strtoupper(trim((string) $a->code)));

        $lines  = [];
        $galat  = [];
        $lewat  = 0;

        foreach ($rows as $n => $r) {
            $baris = $n + 2;
            $kode  = strtoupper(TabelImpor::sel($r, $iKode));
            $dRaw  = TabelImpor::sel($r, $iDebit);
            $kRaw  = TabelImpor::sel($r, $iKred);

            if ($kode === '' && $dRaw === '' && $kRaw === '') {
                continue;
            }

            $a = $akun->get($kode);
            if (! $a) {
                $galat[] = "Baris {$baris}: akun '{$kode}' tidak ditemukan atau bukan akun postable.";
                $lewat++;

                continue;
            }

            $debit  = TabelImpor::angka($dRaw) ?? 0.0;
            $kredit = TabelImpor::angka($kRaw) ?? 0.0;

            if ($debit < 0 || $kredit < 0) {
                $galat[] = "Baris {$baris}: nilai negatif tidak diterima — tukar kolom Debit/Kredit.";
                $lewat++;

                continue;
            }
            if ($debit > 0 && $kredit > 0) {
                $galat[] = "Baris {$baris}: akun {$kode} terisi Debit DAN Kredit sekaligus — isi salah satu saja.";
                $lewat++;

                continue;
            }
            if ($debit < 0.01 && $kredit < 0.01) {
                $galat[] = "Baris {$baris}: akun {$kode} tidak punya nilai Debit maupun Kredit.";
                $lewat++;

                continue;
            }

            $lines[] = [
                'account_id'  => (int) $a->id,
                'debit'       => round($debit, 2),
                'credit'      => round($kredit, 2),
                'description' => TabelImpor::sel($r, $iKet) ?: 'Saldo Awal '.$year,
            ];
        }

        if (! $lines) {
            return back()->with('error', 'Tidak ada baris yang bisa diimpor. '.implode(' ', array_slice($galat, 0, 8)));
        }

        /* Saldo awal HARUS balance sebagai satu kesatuan. Berbeda dari import lain yang boleh
           memasukkan baris baik dan melewati yang jelek, di sini melewati satu baris berarti
           jurnalnya tidak seimbang — dan jurnal saldo awal yang timpang membuat seluruh neraca
           tahun itu salah sejak hari pertama. Karena itu satu baris gagal membatalkan semuanya. */
        $totalD = round(array_sum(array_column($lines, 'debit')), 2);
        $totalK = round(array_sum(array_column($lines, 'credit')), 2);
        if ($galat) {
            return back()->with('error', 'Import dibatalkan — saldo awal harus lengkap & seimbang. '
                .implode(' · ', array_slice($galat, 0, 8)).(count($galat) > 8 ? ' … (+'.(count($galat) - 8).' lainnya)' : ''));
        }
        if (abs($totalD - $totalK) >= 0.01) {
            return back()->with('error', 'Import dibatalkan — total Debit ('.number_format($totalD, 2, ',', '.')
                .') tidak sama dengan total Kredit ('.number_format($totalK, 2, ',', '.').'). Selisih '
                .number_format(abs($totalD - $totalK), 2, ',', '.').'.');
        }

        try {
            $j = $journal->post([
                'journal_date' => sprintf('%04d-12-31', $year - 1),
                'source'       => 'saldo_awal',
                'status'       => 'posted',
                'reference'    => $ref,
                'description'  => 'Saldo Awal '.$year.' (import)',
                'created_by'   => $request->user()->id,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->with('error', 'Import gagal: '.$e->getMessage().' Tidak ada yang tersimpan.');
        }

        return back()->with('success', count($lines).' baris saldo awal diimpor sebagai jurnal '
            .$j->journal_number.' bertanggal 31 Desember '.($year - 1).' — total Rp '
            .number_format($totalD, 0, ',', '.').'.');
    }

    /** Baris jurnal saldo awal tahun {year} = saldo akun permanen s/d 31 Des {year-1} + L/R belum ditutup → Laba Ditahan. @return array<int,array<string,mixed>> */
    private function openingJournalLines(int $year): array
    {
        $cutoff = sprintf('%04d-01-01', $year);
        $rows = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<', $cutoff)
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('a.code, a.name, a.type, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->orderBy('a.code')->get();

        $lines = [];
        $unclosed = 0.0;
        foreach ($rows as $r) {
            if (in_array($r->type, ['asset', 'liability', 'equity'], true)) {
                $net = round((float) $r->net, 2);
                if (abs($net) < 0.01) {
                    continue;
                }
                $lines[] = ['code' => $r->code, 'name' => $r->name, 'debit' => $net > 0 ? $net : 0.0, 'credit' => $net < 0 ? round(-$net, 2) : 0.0];
            } elseif (in_array($r->type, ['income', 'expense'], true)) {
                $unclosed += (float) $r->net; // debit−credit; laba → negatif
            }
        }

        // L/R yang belum dijurnal-tutup → ke Laba Ditahan (agar balance). 0 bila tahun lalu sudah ditutup.
        $profit = round(-1 * $unclosed, 2);
        if (abs($profit) >= 0.01) {
            $ld = BsAccount::where('code', config('business_support.gl_accounts.retained_earnings'))->first();
            $lines[] = [
                'code' => $ld?->code ?? '80101010101', 'name' => ($ld?->name ?? 'Laba Ditahan').' (L/R berjalan belum ditutup)',
                'debit' => $profit < 0 ? round(-$profit, 2) : 0.0, 'credit' => $profit > 0 ? $profit : 0.0,
            ];
        }

        return $lines;
    }

    public function close(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);

        try {
            $svc->closePeriod($v['year'], $v['month']);
        } catch (BusinessRuleException $e) {
            // Jurnal yang belum Approved — pesannya menyebutkan jumlahnya, jadi diteruskan
            // apa adanya alih-alih diganti kalimat umum.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Periode {$v['month']}/{$v['year']} ditutup (dikunci).");
    }

    public function reopen(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $svc->reopenPeriod($v['year'], $v['month']);

        return back()->with('success', "Periode {$v['month']}/{$v['year']} dibuka kembali.");
    }

    public function closeYear(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer']]);

        try {
            $journal = $svc->closeYear($v['year'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = $journal
            ? "Tutup Buku tahun {$v['year']} selesai — jurnal penutup #{$journal->id} terbentuk, 12 bulan dikunci."
            : "Tutup Buku tahun {$v['year']} — tidak ada saldo nominal untuk ditutup, 12 bulan dikunci.";

        return back()->with('success', $msg);
    }

    // ── Confirm Saldo Awal ────────────────────────────────────────────────

    /** Halaman konfirmasi saldo awal: tinjau jurnal saldo awal lalu kunci/konfirmasi. */
    public function confirmSaldoAwal(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.saldo-awal.confirm'), 403);
        $year  = (int) ($request->integer('year') ?: now()->year);
        $lines = $this->openingJournalLines($year);
        $debit  = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        return Inertia::render('BusinessSupport/Gl/ConfirmSaldoAwal', [
            'year'         => $year,
            'lines'        => $lines,
            'totals'       => ['debit' => $debit, 'credit' => $credit, 'balanced' => abs($debit - $credit) < 0.01],
            'confirmation' => BsOpeningConfirmation::where('year', $year)->first(),
        ]);
    }

    public function storeSaldoAwalConfirm(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['year' => ['required', 'integer']]);
        $year  = (int) $v['year'];
        $lines = $this->openingJournalLines($year);
        $debit  = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        BsOpeningConfirmation::updateOrCreate(
            ['year' => $year],
            [
                'total_debit' => $debit, 'total_credit' => $credit, 'balanced' => abs($debit - $credit) < 0.01,
                'confirmed_by' => $request->user()->id, 'confirmed_at' => now(),
            ],
        );

        return back()->with('success', "Saldo Awal {$year} dikonfirmasi & dikunci.");
    }

    // ── Late Closing ──────────────────────────────────────────────────────

    /** Penutupan terlambat: tutup periode mundur yang sudah lewat jatuh tempo, dengan catatan. */
    public function lateClosing(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.late-closing.view'), 403);
        $year = (int) ($request->integer('year') ?: now()->year);
        $now  = now();
        $byMonth = BsFiscalPeriod::where('year', $year)->get()->keyBy('month');

        $periods = collect(range(1, 12))->map(function ($m) use ($year, $byMonth, $now) {
            $p   = $byMonth[$m] ?? null;
            $end = Carbon::create($year, $m, 1)->endOfMonth();

            return [
                'month'       => $m,
                'status'      => $p?->status ?? 'open',
                'closed_at'   => $p?->closed_at,
                'late_closed' => (bool) ($p?->late_closed),
                'late_note'   => $p?->late_note,
                'overdue'     => $now->greaterThan($end) && ($p?->status ?? 'open') !== 'closed',
            ];
        });

        return Inertia::render('BusinessSupport/Gl/LateClosing', [
            'year'    => $year,
            'periods' => $periods,
            'today'   => $now->toDateString(),
        ]);
    }

    public function lateClose(Request $request, PeriodClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'year'  => ['required', 'integer'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'note'  => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $period = $svc->closePeriod($v['year'], $v['month']);
        } catch (BusinessRuleException $e) {
            // Late Closing tetap penguncian periode, jadi syaratnya sama: Late Closing menjawab
            // "terlambat ditutup", bukan "boleh ditutup walau jurnalnya belum selesai".
            return back()->with('error', $e->getMessage());
        }
        $period->update(['late_closed' => true, 'late_note' => $v['note'] ?? null]);

        return back()->with('success', "Periode {$v['month']}/{$v['year']} ditutup (Late Closing).");
    }
}
