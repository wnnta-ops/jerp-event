<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Exports\SheetXlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan GL lanjutan: Neraca Komparasi Tahunan, Neraca Multi Periode, Histori Saldo akun.
 */
class GlExtraReportController extends Controller
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Mode yang menyatakan POSISI pada satu titik waktu → dikendalikan satu tanggal. */
    public const MODE_NERACA = ['tahunan', 'multi', 'histori'];

    /** Mode yang menyatakan HASIL selama satu periode → dikendalikan rentang tanggal. */
    public const MODE_LABA_RUGI = ['lr-multi', 'lr-tahunan', 'lr-banding'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $d = $this->data($request);

        return Inertia::render('BusinessSupport/Gl/ExtraReports', array_merge([
            'mode'      => $d['mode'],
            'year'      => $d['year'],
            'accounts'  => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Neraca dikendalikan satu tanggal, laba rugi dikendalikan rentang — layar
            // menampilkan kontrol yang sesuai modenya, bukan keduanya sekaligus.
            'jenis'     => in_array($d['mode'], self::MODE_LABA_RUGI, true) ? 'laba_rugi' : 'neraca',
            'filters'   => [
                'account_id' => $request->integer('account_id') ?: null,
                'as_of'      => $d['as_of'],
                'date_from'  => $d['date_from'],
                'date_to'    => $d['date_to'],
            ],
        ], $d['laporan']));
    }

    /** Export laporan GL lanjutan yang sedang tampil ke Excel (.xlsx). */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $d = $this->data($request);
        $l = $d['laporan'];

        // Seluruh mode memulangkan bentuk yang sama (judul + kolom + baris berlabel), jadi
        // satu penyusun cukup untuk keenamnya — tidak perlu enam export yang berbeda.
        $rows = [[$l['title']], [], array_merge([$l['rowHeader']], $l['columns'])];
        foreach ($l['rows'] as $r) {
            $label = ($r['code'] ?? '') !== '' ? $r['code'].' '.$r['label'] : $r['label'];
            $rows[] = array_merge([$label], array_map(
                fn ($v) => $v === null ? null : (float) $v,
                $r['values'],
            ));
        }

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $l['title']) ?? 'Laporan';

        return (new SheetXlsx($rows, 3, 'Laporan'))->toResponse(trim($slug, '-').'.xlsx');
    }

    /**
     * Susun laporan sesuai mode + kendali tanggalnya — dipakai bersama layar & export.
     *
     * @return array<string,mixed>
     */
    private function data(Request $request): array
    {
        $mode = in_array($request->input('mode'), array_merge(self::MODE_NERACA, self::MODE_LABA_RUGI), true)
            ? (string) $request->input('mode')
            : 'tahunan';

        // Neraca: satu tanggal (posisi). Laba rugi: rentang (hasil selama periode).
        $asOf = $request->date('as_of') ?? now();
        $to   = $request->date('date_to') ?? now();
        $from = $request->date('date_from') ?? $to->copy()->startOfYear();
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Tahun laporan mengikuti tanggal yang dipilih, bukan parameter terpisah — kalau
        // keduanya ada, keduanya bisa saling bertentangan dan layar tidak punya cara memilih.
        $year = in_array($mode, self::MODE_LABA_RUGI, true) ? (int) $to->year : (int) $asOf->year;

        $laporan = match ($mode) {
            'histori'    => $this->histori($request, $year, $asOf),
            'multi'      => $this->multi($year, $asOf),
            'lr-multi'   => $this->lrMulti($year, $from, $to),
            'lr-tahunan' => $this->lrTahunan($from, $to),
            'lr-banding' => $this->lrBanding($from, $to),
            default      => $this->tahunan($asOf),
        };

        return [
            'mode' => $mode, 'year' => $year, 'laporan' => $laporan,
            'as_of' => $asOf->toDateString(),
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
        ];
    }

    /** Saldo (debit−credit) per akun s/d tanggal. @return \Illuminate\Support\Collection<int,float> */
    private function balanceMap(string $cutoff)
    {
        return DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<=', $cutoff)
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->pluck('net', 'jl.account_id');
    }

    /**
     * Neraca Komparasi: posisi PADA TANGGAL yang dipilih vs tanggal yang sama setahun lalu.
     *
     * Dulu selalu 31 Desember. Membandingkan posisi hari ini dengan akhir tahun lalu bukan
     * perbandingan yang setara — tanggal yang sama setahun sebelumnya baru setara.
     *
     * @return array<string,mixed>
     */
    private function tahunan(\Illuminate\Support\Carbon $asOf): array
    {
        $prev = $this->balanceMap($asOf->copy()->subYearNoOverflow()->toDateString());
        $curr = $this->balanceMap($asOf->toDateString());
        $year = (int) $asOf->year;
        $rows = BsAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            ->map(function ($a) use ($prev, $curr) {
                $sign = $a->normal_balance === 'K' ? -1 : 1;
                $pv = round(((float) ($prev[$a->id] ?? 0)) * $sign, 2);
                $cv = round(((float) ($curr[$a->id] ?? 0)) * $sign, 2);

                return ['code' => $a->code, 'label' => $a->name, 'values' => [$pv, $cv, round($cv - $pv, 2)]];
            })->filter(fn ($r) => abs($r['values'][0]) > 0.001 || abs($r['values'][1]) > 0.001)->values();

        $tgl = $asOf->translatedFormat('d M Y');

        return ['title' => "Neraca Komparasi Tahunan — {$tgl} vs ".$asOf->copy()->subYearNoOverflow()->translatedFormat('d M Y'),
            'rowHeader' => 'Akun',
            'columns' => [(string) ($year - 1), (string) $year, 'Selisih'], 'rows' => $rows];
    }

    /**
     * Neraca Multi Periode: posisi tiap akhir bulan, BERHENTI pada tanggal yang dipilih.
     *
     * Bulan yang belum lewat tidak ditampilkan sebagai nol — kolom nol pada neraca terbaca
     * sebagai "saldonya habis", bukan "bulannya belum sampai".
     *
     * @return array<string,mixed>
     */
    private function multi(int $year, \Illuminate\Support\Carbon $asOf): array
    {
        // Bulan terakhir = bulan tanggal itu bila tahunnya sama; tahun lampau tampil penuh.
        $hingga = (int) $asOf->year === $year ? (int) $asOf->month : 12;
        $kolom = [];
        $maps = [];
        for ($m = 1; $m <= $hingga; $m++) {
            $akhir = \Illuminate\Support\Carbon::create($year, $m, 1)->endOfMonth();
            // Kolom terakhir memakai tanggal yang dipilih, bukan akhir bulan yang belum tiba.
            $batas = $akhir->greaterThan($asOf) ? $asOf : $akhir;
            $maps[$m] = $this->balanceMap($batas->toDateString());
            $kolom[] = $batas->equalTo($akhir) ? self::MONTHS[$m - 1] : self::MONTHS[$m - 1].' ('.$batas->day.')';
        }
        $rows = BsAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            ->map(function ($a) use ($maps, $hingga) {
                $sign = $a->normal_balance === 'K' ? -1 : 1;
                $vals = [];
                for ($m = 1; $m <= $hingga; $m++) {
                    $vals[] = round(((float) ($maps[$m][$a->id] ?? 0)) * $sign, 2);
                }

                return ['code' => $a->code, 'label' => $a->name, 'values' => $vals];
            })->filter(fn ($r) => array_sum(array_map('abs', $r['values'])) > 0.001)->values();

        return ['title' => "Neraca Multi Periode — {$year} s/d ".$asOf->translatedFormat('d M Y'),
            'rowHeader' => 'Akun', 'columns' => $kolom, 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    private function histori(Request $request, int $year, \Illuminate\Support\Carbon $asOf): array
    {
        $accountId = (int) $request->integer('account_id');
        // Berhenti pada bulan tanggal yang dipilih — bulan yang belum tiba tidak ditampilkan
        // sebagai saldo nol, karena nol pada kolom saldo terbaca sebagai "habis".
        $hingga = (int) $asOf->year === $year ? (int) $asOf->month : 12;
        $base = ['title' => 'Histori Saldo Akun — '.$year.' s/d '.$asOf->translatedFormat('d M Y'),
            'rowHeader' => 'Bulan', 'columns' => ['Debit', 'Kredit', 'Saldo'], 'rows' => []];
        if (! $accountId) {
            return $base;
        }

        $opening = (float) DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->where('jl.account_id', $accountId)->whereDate('j.journal_date', '<', sprintf('%04d-01-01', $year))
            ->selectRaw('COALESCE(SUM(jl.debit-jl.credit),0) n')->value('n');
        $mq = DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->where('jl.account_id', $accountId)->whereYear('j.journal_date', $year)
            // Bulan berjalan hanya dihitung sampai tanggalnya, supaya saldo terakhirnya sama
            // dengan saldo yang disebutkan Neraca pada tanggal yang sama.
            ->whereDate('j.journal_date', '<=', $asOf->toDateString())
            ->selectRaw('j.period_month m, COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c')
            ->groupBy('j.period_month')->get()->keyBy('m');

        $running = $opening;
        $rows = [['label' => 'Saldo Awal', 'values' => [null, null, round($opening, 2)]]];
        for ($m = 1; $m <= $hingga; $m++) {
            $d = (float) ($mq[$m]->d ?? 0);
            $c = (float) ($mq[$m]->c ?? 0);
            $running += ($d - $c);
            $rows[] = ['label' => self::MONTHS[$m - 1], 'values' => [$d, $c, round($running, 2)]];
        }
        $base['rows'] = $rows;

        return $base;
    }

    // ── LABA RUGI (aktivitas income/expense dalam periode) ─────────────────

    /** Aktivitas (debit−credit) per akun pada rentang tanggal [from..to]. */
    private function activityMap(string $from, string $to)
    {
        return DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->whereBetween('j.journal_date', [$from, $to])
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->pluck('net', 'jl.account_id');
    }

    /**
     * Susun baris Laba Rugi: tiap akun income/expense ditampilkan positif,
     * lalu baris total "LABA (RUGI) BERSIH" = Σpendapatan − Σbeban per kolom.
     *
     * @param  array<int,\Illuminate\Support\Collection> $maps  satu map per kolom (account_id→net)
     * @return array<string,mixed>
     */
    private function lrAssemble(string $title, array $columns, array $maps): array
    {
        $accs = BsAccount::whereIn('type', ['income', 'expense'])->where('is_postable', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'normal_balance', 'type']);

        $rows = [];
        $net = array_fill(0, count($maps), 0.0);
        foreach ($accs as $a) {
            $sign = $a->normal_balance === 'K' ? -1 : 1;
            $vals = [];
            foreach ($maps as $i => $map) {
                $v = round(((float) ($map[$a->id] ?? 0)) * $sign, 2);
                $vals[] = $v;
                $net[$i] += ($a->type === 'income' ? $v : -$v);
            }
            if (array_sum(array_map('abs', $vals)) > 0.001) {
                $rows[] = ['code' => $a->code, 'label' => $a->name, 'values' => $vals];
            }
        }
        $rows[] = ['code' => '', 'label' => 'LABA (RUGI) BERSIH', 'values' => array_map(fn ($x) => round($x, 2), $net)];

        return ['title' => $title, 'rowHeader' => 'Akun', 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * Laba Rugi per bulan, hanya bulan-bulan yang TERSENTUH rentang tanggalnya.
     *
     * Bulan pertama & terakhir dipotong pada tanggalnya, sehingga jumlah seluruh kolomnya
     * sama persis dengan Laporan Laba Rugi untuk rentang yang sama.
     *
     * @return array<string,mixed>
     */
    private function lrMulti(int $year, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        $maps = [];
        $kolom = [];
        $kursor = $from->copy()->startOfMonth();
        while ($kursor->lessThanOrEqualTo($to)) {
            $awal = $kursor->copy()->startOfMonth();
            $akhir = $kursor->copy()->endOfMonth();
            $mulai = $from->greaterThan($awal) ? $from : $awal;
            $henti = $to->lessThan($akhir) ? $to : $akhir;
            $maps[] = $this->activityMap($mulai->toDateString(), $henti->toDateString());
            $kolom[] = self::MONTHS[$kursor->month - 1]
                .((int) $kursor->year !== $year ? " '".substr((string) $kursor->year, 2) : '');
            $kursor->addMonthNoOverflow();
        }
        if ($maps === []) {
            $maps[] = $this->activityMap($from->toDateString(), $to->toDateString());
            $kolom[] = 'Periode';
        }

        return $this->lrAssemble(
            'Laba Rugi Multi Periode — '.$from->translatedFormat('d M Y').' s/d '.$to->translatedFormat('d M Y'),
            $kolom, $maps,
        );
    }

    /**
     * Laba Rugi periode ini vs periode yang SAMA setahun lalu + selisih.
     *
     * Dibandingkan dengan rentang yang digeser setahun, bukan dengan tahun penuh: 8 bulan
     * berjalan melawan 12 bulan tahun lalu bukan perbandingan, melainkan salah baca.
     *
     * @return array<string,mixed>
     */
    private function lrTahunan(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        $fromLalu = $from->copy()->subYearNoOverflow();
        $toLalu   = $to->copy()->subYearNoOverflow();
        $maps = [
            $this->activityMap($fromLalu->toDateString(), $toLalu->toDateString()),
            $this->activityMap($from->toDateString(), $to->toDateString()),
        ];
        $r = $this->lrAssemble(
            'Laba Rugi Komparasi Tahunan — '.$from->translatedFormat('d M Y').' s/d '.$to->translatedFormat('d M Y')
                .' vs '.$fromLalu->translatedFormat('d M Y').' s/d '.$toLalu->translatedFormat('d M Y'),
            [(string) $fromLalu->year, (string) $from->year], $maps,
        );
        foreach ($r['rows'] as &$row) {
            $row['values'][] = round(($row['values'][1] ?? 0) - ($row['values'][0] ?? 0), 2);
        }
        unset($row);
        $r['columns'][] = 'Selisih';

        return $r;
    }

    /**
     * Laba Rugi perbandingan 3 periode: rentang ini dan rentang yang sama pada dua tahun
     * sebelumnya.
     *
     * @return array<string,mixed>
     */
    private function lrBanding(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): array
    {
        $maps = [];
        $kolom = [];
        foreach ([2, 1, 0] as $mundur) {
            $f = $from->copy()->subYearsNoOverflow($mundur);
            $t = $to->copy()->subYearsNoOverflow($mundur);
            $maps[] = $this->activityMap($f->toDateString(), $t->toDateString());
            $kolom[] = (string) $f->year;
        }

        return $this->lrAssemble(
            'Laba Rugi Perbandingan — '.$from->translatedFormat('d M').' s/d '.$to->translatedFormat('d M')
                .', '.implode(' · ', $kolom),
            $kolom, $maps,
        );
    }
}
