<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\JournalPartyResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PIUTANG LAIN — Piutang Usaha (Booked) → Piutang Lain.
 *
 *   Saldo Awal   = mutasi akun piutang lain SEBELUM tanggal periode awal
 *   Penambahan   = DEBIT dalam periode (pinjaman diberikan)
 *   Pelunasan    = KREDIT dalam periode (pinjaman dibayar kembali)
 *   Saldo Akhir  = Saldo Awal + Penambahan − Pelunasan
 *
 * Akun yang dibaca: seluruh anak akun 101030101 "PIUTANG LAIN-LAIN" yang boleh menerima jurnal —
 * hari ini PIUTANG PEMEGANG SAHAM, PIUTANG KARYAWAN, dan PIUTANG LAINNYA.
 *
 * ── Kenapa dibaca dari BUKU BESAR, bukan dari sebuah tabel pinjaman ──
 * Tidak ada tabel pinjaman untuk ketiga akun ini. Saldonya bergerak lewat pintu yang bentuknya
 * berbeda-beda: memorial, kas/bank, giro (GiroService mengkredit akun lawan yang dipilih), dan
 * BG/cek keluar (OutgoingChequeService mendebit akun yang dipilih). Menjumlahkan sebuah tabel
 * berarti menebak ulang apa yang sudah dicatat jurnalnya, dan setiap pintu baru akan luput
 * diam-diam. Dengan membaca mutasi akunnya, angka layar ini selalu sama dengan buku besarnya.
 *
 * ── Kenapa HANYA jurnal 'posted' yang dihitung ──
 * Buku Besar (LedgerReportController::accountLedger), Neraca Saldo, dan Neraca sama-sama hanya
 * menghitung status 'posted'. Ikut menjumlahkan jurnal yang belum disetujui akan membuat Saldo
 * Akhir di sini TIDAK PERNAH sama dengan Neraca — tanpa satu pun galat yang memberi tahu.
 * Predikatnya disalin persis dari accountLedger() supaya keduanya tidak bisa berbeda.
 *
 * ── Kenapa memakai Eloquent whereHas, bukan DB::table + join ──
 * BsJournal memakai global scope tenant + SoftDeletes. whereHas('journal', …) membawa keduanya
 * apa adanya; join mentah kehilangan keduanya, dan menambalnya dengan
 * where('tenant_id', tenant_id()) justru lebih buruk — tenant_id() memulangkan NULL di luar
 * request ber-middleware, dan Laravel mengubah where(kolom, null) menjadi "IS NULL" sehingga
 * layarnya kosong tanpa alasan yang terbaca.
 *
 * ── Siapa "peminjam" ──
 * Buku besar tidak menyimpan kolom pihak. Ada dua sumber, dan keduanya dipakai berurutan:
 *  1. KETERANGAN BARIS JURNAL (bs_journal_lines.description) — satu-satunya identitas PER BARIS.
 *  2. Penelusuran balik jurnal → dokumen → pihak (JournalPartyResolver), yang bekerja PER JURNAL.
 * Urutannya disengaja. Resolver lebih rapi namanya, tetapi ia memulangkan SATU nama untuk satu
 * jurnal: memorial yang mendebit Piutang Karyawan untuk lima orang dalam lima baris akan
 * menumpuk seluruh uangnya ke satu nama. Keterangan baris bisa terbaca kurang rapi, tetapi ia
 * tidak pernah memindahkan uang seseorang ke nama orang lain. Nama dari resolver tetap
 * ditampilkan di kolomnya sendiri pada rincian, jadi tidak ada yang disembunyikan.
 * Baris yang tidak punya keduanya dikumpulkan sebagai "(tanpa peminjam)" — DITAMPILKAN, bukan
 * dibuang, sebab baris yang hilang membuat jumlah layar tidak lagi sama dengan saldo akunnya.
 */
class PiutangLainController extends Controller
{
    /** Akun induk "PIUTANG LAIN-LAIN"; anak-anaknya yang postable adalah akun piutang lain. */
    public const KODE_INDUK = '101030101';

    private const TANPA_PEMINJAM = '(tanpa peminjam)';

    public function index(Request $request, JournalPartyResolver $party): Response
    {
        abort_unless($request->user()?->can('ar.piutang-lain.view'), 403);

        [$from, $to] = $this->periode($request);
        $mode = $request->string('mode')->toString() === 'detail' ? 'detail' : 'summary';
        $peminjam = trim((string) $request->string('peminjam'));
        $akunId = $request->integer('account_id') ?: null;

        $akun = $this->akunPiutangLain();
        $akunTerpilih = $akunId !== null && $akun->contains('id', $akunId) ? [$akunId] : $akun->pluck('id')->all();

        if ($akun->isEmpty()) {
            return Inertia::render('BusinessSupport/Receivable/PiutangLain', $this->kosong($from, $to, $mode, $peminjam, $akunId));
        }

        $baris = $this->barisAkun($akunTerpilih, $to);
        $pihak = $party->forJournalIds($baris->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $namaAkun = $akun->pluck('name', 'id');

        $rekap = [];
        $rincian = [];

        foreach ($baris as $b) {
            $nama = $this->namaPeminjam($b, $pihak);
            $rekap[$nama] ??= ['awal' => 0.0, 'tambah' => 0.0, 'lunas' => 0.0];

            $tgl = substr((string) $b->journal->journal_date, 0, 10);
            $debit = (float) $b->debit;
            $kredit = (float) $b->credit;

            if ($tgl < $from) {
                // Saldo awal = akumulasi mutasi SEBELUM tanggal periode awal. Bukan angka
                // tersimpan, dan bukan hasil menyaring periode: menyaringnya dengan rentang yang
                // sama seperti mutasi akan membuat saldo awal selalu nol, dan layarnya tetap
                // terbaca masuk akal.
                $rekap[$nama]['awal'] += $debit - $kredit;

                continue;
            }

            $rekap[$nama]['tambah'] += $debit;
            $rekap[$nama]['lunas'] += $kredit;

            if ($mode === 'detail' && $peminjam !== '' && $nama === $peminjam) {
                $rincian[] = [
                    'date'           => $tgl,
                    'journal_id'     => (int) $b->journal_id,
                    'journal_number' => $b->journal?->journal_number,
                    'reference'      => $b->journal?->reference,
                    'account'        => (string) ($namaAkun[$b->account_id] ?? ''),
                    'description'    => $b->description ?: $b->journal?->description,
                    // Nama hasil penelusuran balik ditampilkan berdampingan, bukan menggantikan:
                    // ia berlaku untuk SELURUH jurnal, jadi pada jurnal banyak-orang ia bukan
                    // pemilik baris ini — operator berhak melihat keduanya dan menilai sendiri.
                    'pihak_jurnal'   => $pihak[(int) $b->journal_id] ?? null,
                    'debit'          => $debit,
                    'credit'         => $kredit,
                ];
            }
        }

        $rows = collect($rekap)->map(fn (array $v, string $nama) => [
            'peminjam'    => $nama,
            'saldo_awal'  => round($v['awal'], 2),
            'penambahan'  => round($v['tambah'], 2),
            'pelunasan'   => round($v['lunas'], 2),
            'saldo_akhir' => round($v['awal'] + $v['tambah'] - $v['lunas'], 2),
        ])->values()
            // Baris yang seluruh angkanya nol tidak menambah apa pun selain panjangnya daftar.
            ->filter(fn (array $r) => abs($r['saldo_awal']) >= 0.01 || abs($r['penambahan']) >= 0.01
                || abs($r['pelunasan']) >= 0.01 || abs($r['saldo_akhir']) >= 0.01)
            ->sortBy('peminjam')->values();

        // Saldo berjalan dihitung DI SINI, bukan di layar: layar hanya menampilkan baris yang
        // lolos saringan kolomnya, sehingga saldo berjalan yang dihitung di sana akan melompat.
        $jalan = $rows->firstWhere('peminjam', $peminjam)['saldo_awal'] ?? 0.0;
        $rincian = array_map(function (array $d) use (&$jalan) {
            $jalan = round($jalan + $d['debit'] - $d['credit'], 2);

            return $d + ['saldo' => $jalan];
        }, $rincian);

        return Inertia::render('BusinessSupport/Receivable/PiutangLain', [
            'rows'     => $rows,
            'detail'   => $rincian,
            'peminjams' => $rows->pluck('peminjam')->values(),
            'totals'   => [
                'saldo_awal'  => round((float) $rows->sum('saldo_awal'), 2),
                'penambahan'  => round((float) $rows->sum('penambahan'), 2),
                'pelunasan'   => round((float) $rows->sum('pelunasan'), 2),
                'saldo_akhir' => round((float) $rows->sum('saldo_akhir'), 2),
                'peminjam'    => $rows->count(),
            ],
            'filters'  => ['from' => $from, 'to' => $to, 'mode' => $mode, 'peminjam' => $peminjam, 'account_id' => $akunId],
            'accounts' => $akun->map(fn ($a) => ['id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name])->values(),
        ]);
    }

    /**
     * Akun piutang lain = anak akun 101030101 yang boleh menerima jurnal.
     *
     * DICARI LEWAT KODE INDUK, bukan lewat nama dan bukan lewat id yang dipatok:
     *  - Nama tidak bisa dipakai: ADA TIGA akun bertingkat yang sama-sama bernama
     *    "PIUTANG LAIN-LAIN", dan dua di antaranya bukan akun posting. Mencocokkan nama akan
     *    memulangkan akun yang salah, atau hanya satu dari tiga akun yang benar.
     *  - id tidak stabil antar basis data/tenant (bs_accounts ber-tenant).
     * Dengan bersandar pada kode induk, akun keempat yang kelak ditambahkan operator di bawah
     * induk yang sama IKUT OTOMATIS — dan itu memang yang diinginkan: layar ini menjanjikan
     * "seluruh piutang lain", bukan "tiga akun yang kebetulan saya tuliskan".
     *
     * @return \Illuminate\Support\Collection<int,BsAccount>
     */
    private function akunPiutangLain()
    {
        return BsAccount::query()
            ->where('parent_code', self::KODE_INDUK)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Baris mutasi akun-akun itu sampai tanggal akhir periode.
     *
     * Predikatnya SAMA PERSIS dengan LedgerReportController::accountLedger() — hanya jurnal
     * 'posted' — supaya Saldo Akhir di sini tidak bisa berbeda dari Buku Besar dan Neraca.
     *
     * @param  list<int>  $accountIds
     * @return \Illuminate\Support\Collection<int,BsJournalLine>
     */
    private function barisAkun(array $accountIds, string $to)
    {
        return BsJournalLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->whereDate('journal_date', '<=', $to))
            ->with('journal:id,journal_number,journal_date,reference,description')
            ->get()
            ->sortBy(fn ($l) => [(string) $l->journal?->journal_date, (int) $l->id])
            ->values();
    }

    /** @param  array<int,string>  $pihak */
    private function namaPeminjam(BsJournalLine $baris, array $pihak): string
    {
        $dariBaris = trim((string) $baris->description);
        if ($dariBaris !== '') {
            return $dariBaris;
        }

        $dariJurnal = trim((string) ($pihak[(int) $baris->journal_id] ?? ''));

        return $dariJurnal !== '' ? $dariJurnal : self::TANPA_PEMINJAM;
    }

    /** @return array{0:string,1:string} */
    private function periode(Request $request): array
    {
        $to = $request->string('to')->toString() ?: Carbon::now()->endOfMonth()->toDateString();
        $from = $request->string('from')->toString() ?: Carbon::parse($to)->startOfMonth()->toDateString();

        // Rentang terbalik menghasilkan daftar kosong yang terbaca seperti "tidak ada data",
        // padahal yang salah tanggalnya — ditukar saja, bukan didiamkan.
        return strtotime($from) > strtotime($to) ? [$to, $from] : [$from, $to];
    }

    /** @return array<string,mixed> */
    private function kosong(string $from, string $to, string $mode, string $peminjam, ?int $akunId): array
    {
        return [
            'rows' => [], 'detail' => [], 'peminjams' => [],
            'totals' => ['saldo_awal' => 0.0, 'penambahan' => 0.0, 'pelunasan' => 0.0, 'saldo_akhir' => 0.0, 'peminjam' => 0],
            'filters' => ['from' => $from, 'to' => $to, 'mode' => $mode, 'peminjam' => $peminjam, 'account_id' => $akunId],
            'accounts' => [],
        ];
    }
}
