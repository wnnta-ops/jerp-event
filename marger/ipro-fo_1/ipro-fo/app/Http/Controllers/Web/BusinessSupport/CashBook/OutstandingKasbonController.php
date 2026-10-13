<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Support\AksesBon;
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
        abort_unless($request->user()?->can('cashbook.advance.report'), 403);

        return Inertia::render('BusinessSupport/CashBook/OutstandingKasbon', $this->data($request));
    }

    /**
     * Cetak / PDF — halaman A4 yang langsung membuka dialog cetak; PDF disimpan lewat pilihan
     * "Simpan sebagai PDF" di dialog itu (BO tidak memasang mesin PDF di server). Isi & saringannya
     * sama persis dengan layar: Summary, atau Detail satu karyawan bila sedang dibuka.
     */
    public function cetak(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.advance.report'), 403);
        $d = $this->data($request);
        [$judul, $kepala, $isi, $kaki] = $this->tabelEkspor($d);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $co = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $periode = Carbon::parse($d['filters']['from'])->translatedFormat('d M Y').' s/d '.Carbon::parse($d['filters']['to'])->translatedFormat('d M Y');
        $akun = $d['account'] ? $d['account']['code'].' — '.$d['account']['name'] : '-';
        $sel = fn (array $baris, string $tag = 'td') => '<tr>'.implode('', array_map(
            fn ($v, $i) => '<'.$tag.($i > 0 ? ' class="r"' : '').'>'.(is_float($v) || is_int($v) ? $e(number_format((float) $v, 0, ',', '.')) : $e($v)).'</'.$tag.'>',
            $baris, array_keys($baris))).'</tr>';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($judul.' '.$d['filters']['from'].' sd '.$d['filters']['to']).'</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#0f766e}.muted{color:#6b7280;font-size:10px}
            .head{border-bottom:2px solid #0f766e;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            table{border-collapse:collapse;width:100%;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            thead th{background:#f1f5f9}.r{text-align:right;font-variant-numeric:tabular-nums}tfoot td{font-weight:bold;background:#f8fafc}
            @media print{.noprint{display:none}}</style></head><body>'
            .'<div class="head"><div><h1>'.$e($judul).'</h1><div class="muted">'.$e($co).' · Akun '.$e($akun).'</div></div>'
            .'<div class="muted" style="text-align:right">Periode '.$e($periode).'<br>Dicetak '.$e(now()->translatedFormat('d F Y H:i')).'</div></div>'
            .'<table><thead>'.$sel($kepala, 'th').'</thead><tbody>'.implode('', array_map($sel, $isi)).'</tbody>'
            .($kaki ? '<tfoot>'.$sel($kaki).'</tfoot>' : '').'</table>'
            .'<p class="noprint" style="margin-top:12px"><button onclick="window.print()">Cetak / Simpan PDF</button></p>'
            .'<script>window.onload=function(){window.print()}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Excel (.xlsx) — isi & saringannya sama persis dengan layar. */
    public function excel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.advance.report'), 403);
        $d = $this->data($request);
        [$judul, $kepala, $isi, $kaki] = $this->tabelEkspor($d);

        $nama = str_replace(' ', '-', $judul).'-'.$d['filters']['from'].'-sd-'.$d['filters']['to'].'.xlsx';

        return response(\App\Support\Xlsx::tulis($kepala, $kaki ? [...$isi, $kaki] : $isi, 'Outstanding Kasbon'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
        ]);
    }

    /**
     * Tabel yang diekspor: Summary seluruh karyawan yang boleh dilihat, atau Detail mutasi satu
     * karyawan bila mode Detail sedang terbuka.
     *
     * @param  array<string,mixed>  $d
     * @return array{0:string,1:list<string>,2:list<list<string|float>>,3:?list<string|float>}
     */
    private function tabelEkspor(array $d): array
    {
        if ($d['filters']['mode'] === 'detail' && $d['filters']['holder'] !== '') {
            $isi = array_map(fn ($r) => [$r['date'], (string) ($r['journal_number'] ?? ''), (string) ($r['description'] ?? ''),
                (float) $r['debit'], (float) $r['credit'], (float) $r['saldo']], $d['detail']);

            return ['Outstanding Kasbon '.$d['filters']['holder'], ['Tanggal', 'No. Jurnal', 'Keterangan', 'Penambahan', 'Realisasi', 'Saldo'], $isi, null];
        }

        $isi = array_map(fn ($r) => [$r['holder'], (float) $r['saldo_awal'], (float) $r['penambahan'], (float) $r['realisasi'], (float) $r['outstanding']],
            collect($d['rows'])->all());
        $t = $d['totals'];

        return ['Outstanding Kasbon', ['Nama Karyawan', 'Saldo Awal Kasbon', 'Penambahan Kasbon', 'Realisasi Kasbon', 'Outstanding Kasbon'], $isi,
            ['Total ('.$t['karyawan'].' karyawan)', (float) $t['saldo_awal'], (float) $t['penambahan'], (float) $t['realisasi'], (float) $t['outstanding']]];
    }

    /**
     * Seluruh isi layar — dipakai bersama oleh layar, Cetak/PDF, dan Excel supaya ketiganya
     * tidak pernah menunjukkan angka atau karyawan yang berbeda.
     *
     * HANYA KASBON MILIKNYA SENDIRI, kecuali Finance & penyetuju (AksesBon) — aturan yang sama
     * dengan daftar Bon Sementara. Pemegang lain disaring di sini, di server: menyembunyikannya
     * di layar saja tetap mengirim angka orang lain ke peramban.
     *
     * @return array<string,mixed>
     */
    private function data(Request $request): array
    {
        [$from, $to] = $this->period($request);
        $mode = $request->string('mode')->toString() === 'detail' ? 'detail' : 'summary';
        $holder = trim((string) $request->string('holder'));

        $akun = BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->first(['id', 'code', 'name']);
        if (! $akun) {
            return [
                'rows' => [], 'detail' => [], 'holders' => [], 'totals' => $this->totalKosong(),
                'filters' => ['from' => $from, 'to' => $to, 'mode' => $mode, 'holder' => $holder],
                'account' => null,
            ];
        }

        $semua = AksesBon::lihatSemua($request->user());
        $boleh = $semua ? [] : array_flip(AksesBon::holderMilik($request->user()));

        $pemilik = $this->pemilikPerJurnal();
        $baris = $this->barisAkun((int) $akun->id, $to);

        $rekap = [];   // holder => [awal, tambah, realisasi]
        $detail = [];

        foreach ($baris as $b) {
            $nama = $pemilik[(int) $b->journal_id] ?? '(tanpa pemegang)';
            if (! $semua && ! isset($boleh[$nama])) {
                continue;   // kasbon orang lain — tidak untuk pengguna ini
            }
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

        return [
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
            // Layar memberi tahu bahwa yang tampil hanya kasbon miliknya sendiri.
            'hanyaMilikSendiri' => ! $semua,
        ];
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
