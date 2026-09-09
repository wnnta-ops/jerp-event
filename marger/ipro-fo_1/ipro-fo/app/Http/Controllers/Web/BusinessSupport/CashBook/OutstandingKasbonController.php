<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * OUTSTANDING KASBON per karyawan — Cash Book → Master → Outstanding Kasbon.
 *
 *   Saldo Awal Kasbon   = mutasi Uang Muka Operasional karyawan itu SEBELUM tanggal periode awal
 *   Penambahan Kasbon   = DEBIT Uang Muka Operasional dalam periode (kasbon diberikan)
 *   Realisasi Kasbon    = KREDIT Uang Muka Operasional dalam periode (dipertanggungjawabkan)
 *   Outstanding Kasbon  = Saldo Awal + Penambahan − Realisasi
 *
 * DIBACA DARI BUKU BESAR, BUKAN DARI TABEL BON. Uang Muka Operasional bergerak lewat beberapa
 * pintu yang tidak sama bentuknya: pembayaran bon reguler, ALOKASI KASBON bon Debit VA,
 * realisasi, refund, dan reimburse. Menjumlahkan kolom-kolom di bs_cash_advances berarti
 * menebak ulang apa yang sudah dicatat jurnalnya — dan setiap pintu baru akan diam-diam luput.
 * Dengan membaca mutasi akunnya, angka layar ini selalu sama dengan buku besarnya.
 *
 * PEMILIK BARIS ditentukan dengan menelusuri balik jurnalnya ke bon yang menerbitkannya (empat
 * pintu: pembayaran, alokasi, realisasi, penyelesaian). Baris yang tidak bisa ditelusuri TIDAK
 * dibuang — ia dikumpulkan sebagai "(tanpa pemegang)", sebab baris yang hilang diam-diam membuat
 * jumlah layar tidak lagi sama dengan saldo akunnya.
 */
class OutstandingKasbonController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        [$from, $to] = $this->period($request);
        $mode = $request->string('mode')->toString() === 'detail' ? 'detail' : 'summary';
        $holder = trim((string) $request->string('holder'));

        $akun = BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->first(['id', 'code', 'name']);
        if (! $akun) {
            return Inertia::render('BusinessSupport/CashBook/OutstandingKasbon', [
                'rows' => [], 'detail' => [], 'holders' => [], 'totals' => $this->totalKosong(),
                'filters' => ['from' => $from, 'to' => $to, 'mode' => $mode, 'holder' => $holder],
                'account' => null,
            ]);
        }

        $pemilik = $this->pemilikPerJurnal();
        $baris = $this->barisAkun((int) $akun->id, $to);

        $rekap = [];   // holder => [awal, tambah, realisasi]
        $detail = [];

        foreach ($baris as $b) {
            $nama = $pemilik[(int) $b->journal_id] ?? '(tanpa pemegang)';
            $rekap[$nama] ??= ['awal' => 0.0, 'tambah' => 0.0, 'realisasi' => 0.0];

            $tgl = substr((string) $b->journal_date, 0, 10);
            $debit = (float) $b->debit;
            $credit = (float) $b->credit;

            if ($tgl < $from) {
                // Saldo awal = akumulasi mutasi sebelum tanggal periode awal, bukan angka tersimpan.
                $rekap[$nama]['awal'] += $debit - $credit;

                continue;
            }

            $rekap[$nama]['tambah'] += $debit;
            $rekap[$nama]['realisasi'] += $credit;

            if ($mode === 'detail' && $holder !== '' && $nama === $holder) {
                $detail[] = [
                    'date'           => $tgl,
                    'journal_id'     => (int) $b->journal_id,
                    'journal_number' => $b->journal_number,
                    'reference'      => $b->reference,
                    'description'    => $b->line_desc ?: $b->journal_desc,
                    'debit'          => $debit,
                    'credit'         => $credit,
                ];
            }
        }

        $rows = collect($rekap)->map(fn (array $v, string $nama) => [
            'holder'      => $nama,
            'saldo_awal'  => round($v['awal'], 2),
            'penambahan'  => round($v['tambah'], 2),
            'realisasi'   => round($v['realisasi'], 2),
            'outstanding' => round($v['awal'] + $v['tambah'] - $v['realisasi'], 2),
        ])->values()
            // Baris yang seluruh angkanya nol tidak menambah apa pun selain panjangnya daftar.
            ->filter(fn (array $r) => abs($r['saldo_awal']) >= 0.01 || abs($r['penambahan']) >= 0.01
                || abs($r['realisasi']) >= 0.01 || abs($r['outstanding']) >= 0.01)
            ->sortBy('holder')->values();

        // Saldo berjalan dihitung DI SINI, bukan di layar: layar hanya menampilkan baris yang
        // lolos saringan kolomnya, sehingga saldo berjalan yang dihitung di sana akan melompat.
        $jalan = $rows->firstWhere('holder', $holder)['saldo_awal'] ?? 0.0;
        $detail = array_map(function (array $d) use (&$jalan) {
            $jalan = round($jalan + $d['debit'] - $d['credit'], 2);

            return $d + ['saldo' => $jalan];
        }, $detail);

        return Inertia::render('BusinessSupport/CashBook/OutstandingKasbon', [
            'rows'    => $rows,
            'detail'  => $detail,
            'holders' => $rows->pluck('holder')->values(),
            'totals'  => [
                'saldo_awal'  => round((float) $rows->sum('saldo_awal'), 2),
                'penambahan'  => round((float) $rows->sum('penambahan'), 2),
                'realisasi'   => round((float) $rows->sum('realisasi'), 2),
                'outstanding' => round((float) $rows->sum('outstanding'), 2),
                'karyawan'    => $rows->count(),
            ],
            'filters' => ['from' => $from, 'to' => $to, 'mode' => $mode, 'holder' => $holder],
            'account' => ['code' => $akun->code, 'name' => $akun->name],
        ]);
    }

    /**
     * Baris mutasi akun Uang Muka Operasional sampai tanggal akhir periode.
     *
     * Hanya jurnal APPROVED (posted) dan belum terhapus — sepola dengan Rekening Ledger, supaya
     * angka outstanding tidak ikut bergerak oleh jurnal yang masih menunggu persetujuan.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function barisAkun(int $accountId, string $to)
    {
        return DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.account_id', $accountId)
            ->where('j.status', 'posted')
            ->whereNull('j.deleted_at')
            ->whereDate('j.journal_date', '<=', $to)
            ->orderBy('j.journal_date')->orderBy('jl.id')
            ->get(['jl.journal_id', 'jl.debit', 'jl.credit', 'jl.description as line_desc',
                'j.journal_number', 'j.journal_date', 'j.reference', 'j.description as journal_desc']);
    }

    /**
     * Peta journal_id => nama pemegang bon, lewat EMPAT pintu jurnal sebuah bon.
     *
     * Alokasi Kasbon punya tabelnya sendiri (bs_cash_advance_allocations.journal_id): sejak
     * pembayaran Debit VA tidak berjurnal, justru jurnal alokasi inilah satu-satunya yang
     * mendebit Uang Muka Operasional untuk bon Debit VA. Melewatkannya berarti seluruh kasbon
     * Debit VA muncul sebagai "(tanpa pemegang)".
     *
     * @return array<int,string>
     */
    private function pemilikPerJurnal(): array
    {
        $peta = [];

        BsCashAdvance::query()
            ->whereNotNull('holder')
            ->get(['id', 'holder', 'journal_id', 'realize_journal_id', 'settle_journal_id'])
            ->each(function (BsCashAdvance $a) use (&$peta): void {
                foreach ([$a->journal_id, $a->realize_journal_id, $a->settle_journal_id] as $jid) {
                    if ($jid) {
                        $peta[(int) $jid] = (string) $a->holder;
                    }
                }
            });

        BsCashAdvanceAllocation::query()
            // Kolomnya disebut lengkap dengan nama tabelnya: bs_cash_advances juga punya
            // journal_id, jadi tanpa awalan MySQL menolaknya sebagai ambigu.
            ->whereNotNull('bs_cash_advance_allocations.journal_id')
            ->join('bs_cash_advances as a', 'a.id', '=', 'bs_cash_advance_allocations.cash_advance_id')
            ->get(['bs_cash_advance_allocations.journal_id as jid', 'a.holder'])
            ->each(function ($r) use (&$peta): void {
                $peta[(int) $r->jid] = (string) $r->holder;
            });

        return $peta;
    }

    /** @return array{0:string,1:string} */
    private function period(Request $request): array
    {
        $to = $request->string('to')->toString() ?: Carbon::now()->endOfMonth()->toDateString();
        $from = $request->string('from')->toString() ?: Carbon::parse($to)->startOfMonth()->toDateString();

        // Rentang terbalik menghasilkan daftar kosong yang terbaca seperti "tidak ada data",
        // padahal yang salah tanggalnya — ditukar saja, bukan didiamkan.
        return strtotime($from) > strtotime($to) ? [$to, $from] : [$from, $to];
    }

    /** @return array<string,float|int> */
    private function totalKosong(): array
    {
        return ['saldo_awal' => 0, 'penambahan' => 0, 'realisasi' => 0, 'outstanding' => 0, 'karyawan' => 0];
    }
}
