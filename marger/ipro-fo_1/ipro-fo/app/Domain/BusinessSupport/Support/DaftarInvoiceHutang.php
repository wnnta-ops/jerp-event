<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daftar Invoice Hutang / Reimburse / Deposit — DISARING & DIHALAMANKAN DI SERVER.
 *
 * Dulu seluruh invoice (6 ribu lebih) dikirim ke browser lalu disaring di sana, sehingga
 * halamannya lambat. Kini server yang menyaring, merangkum, dan memotongnya per 25 baris.
 *
 * Nilai saringan tetap BENTUK TAMPILAN yang sama dengan yang dilihat orang di layar
 * ("20 Okt 2026", "Rp 9.287.000", "Refund: SPR", "Lunas"), karena dropdown kolom memang
 * berisi nilai tampilan. Setiap kolom punya ekspresi SQL yang menghasilkan bentuk tampilan
 * itu, jadi pencocokan "berisi" (ketik) maupun "persis" (centang) dikerjakan database.
 *
 * Ringkasan (Sisa Hutang, Jatuh Tempo, jumlah tagihan) dihitung dari SELURUH hasil saringan,
 * bukan dari 25 baris yang tampil — angkanya sama dengan sebelum ada halaman.
 */
final class DaftarInvoiceHutang
{
    public const PER_HALAMAN = 25;

    /** Batas isi dropdown per kolom — disebutkan ke layar bila terpotong. */
    public const BATAS_OPSI = 1000;

    /** Kunci kolom saringan (sama dengan layar). */
    public const KOLOM = ['no', 'tgl', 'terima', 'supplier', 'sumber', 'ket', 'total', 'sisa', 'status', 'jurnal', 'berkas', 'berkasFinance'];

    private const BULAN = "'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'";

    public function __construct(private readonly string $jenis) {}

    /** Ekspresi nilai tampilan per kolom. Alias tabel: s = supplier, c = customer (invoice memakai nama tabel penuh — TenantScope menulisnya begitu). */
    public static function ekspresi(string $kolom): string
    {
        $tgl = fn (string $c) => "COALESCE(CONCAT(LPAD(DAY($c),2,'0'),' ',ELT(MONTH($c),".self::BULAN."),' ',YEAR($c)),'—')";

        return match ($kolom) {
            'no' => "COALESCE(bs_ap_invoices.invoice_number,'')",
            'tgl' => $tgl('bs_ap_invoices.invoice_date'),
            'terima' => $tgl('bs_ap_invoices.receive_date'),
            'berkasFinance' => $tgl('bs_ap_invoices.berkas_finance_date'),
            'supplier' => "COALESCE(NULLIF(s.name,''), NULLIF(c.full_name,''), '—')",
            'sumber' => "CASE WHEN bs_ap_invoices.source_type='refund' THEN (CASE bs_ap_invoices.refund_source WHEN 'SPR' THEN 'Refund: SPR' WHEN 'SPRS' THEN 'Refund: SPRS' WHEN 'TRANSFER_ANONIM' THEN 'Refund: Transfer Anonim' ELSE 'Refund' END)"
                ." WHEN bs_ap_invoices.source_type='komisi' THEN 'Komisi' WHEN bs_ap_invoices.source_type='blt' THEN 'BLT' WHEN bs_ap_invoices.source_type='gmc' THEN 'GMC'"
                ." WHEN bs_ap_invoices.source_type='po' THEN 'PO' WHEN bs_ap_invoices.source_type='spk' THEN 'SPK' ELSE 'Manual' END",
            'ket' => "COALESCE(bs_ap_invoices.description,'')",
            'status' => "CASE bs_ap_invoices.status WHEN 'paid' THEN 'Lunas' WHEN 'batal' THEN 'Batal' ELSE 'Open' END",
            'berkas' => "COALESCE(NULLIF(bs_ap_invoices.berkas_notes,''),'—')",
            'jurnal' => self::STATUS_JURNAL,
            'total' => self::BAYAR,
            'sisa' => self::SISA,
        };
    }

    /**
     * Status jurnal invoice — aturannya sama dengan General Ledger: periode yang sudah ditutup
     * terbaca 'Closed' apa pun statusnya; selain itu Baru (draft) / Final / Approved (posted).
     * Invoice tanpa jurnal (mis. saldo awal) → '—'.
     */
    private const STATUS_JURNAL = "CASE WHEN j.id IS NULL THEN '—'"
        ." WHEN EXISTS (SELECT 1 FROM bs_fiscal_periods fp WHERE fp.year = j.period_year AND fp.month = j.period_month AND fp.status = 'closed') THEN 'Closed'"
        ." WHEN j.status = 'posted' THEN 'Approved' WHEN j.status = 'final' THEN 'Final' ELSE 'Baru' END";

    /** Nett dibayar = net_payable bila terisi, kalau tidak total (BsApInvoice::payable_amount). */
    private const BAYAR = 'ROUND(CASE WHEN bs_ap_invoices.net_payable > 0 THEN bs_ap_invoices.net_payable ELSE bs_ap_invoices.total END, 2)';

    /** Sisa = nett − terbayar; invoice Batal = 0 (BsApInvoice::outstanding). */
    private const SISA = "ROUND(CASE WHEN bs_ap_invoices.status='batal' THEN 0 ELSE (CASE WHEN bs_ap_invoices.net_payable > 0 THEN bs_ap_invoices.net_payable ELSE bs_ap_invoices.total END) - bs_ap_invoices.paid_amount END, 2)";

    /** Query dasar: jenis ini, invoice batal tetap tampil (berlabel), soft delete lain disembunyikan. */
    public function dasar(): Builder
    {
        return BsApInvoice::withTrashed()
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'bs_ap_invoices.supplier_id')
            ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 'bs_ap_invoices.customer_id')->whereNull('c.deleted_at'))
            ->leftJoin('bs_journals as j', fn ($x) => $x->on('j.id', '=', 'bs_ap_invoices.journal_id')->whereNull('j.deleted_at'))
            ->where(fn ($q) => $q->whereNull('bs_ap_invoices.deleted_at')->orWhere('bs_ap_invoices.status', BsApInvoice::STATUS_BATAL))
            ->where('bs_ap_invoices.jenis', $this->jenis);
    }

    /** Terapkan pencarian global + saringan per kolom dari request. */
    public function saring(Builder $q, Request $r): Builder
    {
        if (($cari = trim((string) $r->input('search'))) !== '') {
            $q->where(function ($w) use ($cari) {
                foreach (self::KOLOM as $k) {
                    $this->berisi($w, $k, $cari, 'or');
                }
            });
        }

        foreach (self::KOLOM as $k) {
            if (($t = trim((string) $r->input("t.$k"))) !== '') {
                $this->berisi($q, $k, $t, 'and');
            }
            $nilai = array_values(array_filter((array) $r->input("c.$k", []), fn ($v) => $v !== null));
            if ($nilai) {
                $this->persis($q, $k, $nilai);
            }
        }

        return $q;
    }

    /** Kolom berisi teks (tidak peka huruf). Angka dicocokkan pada digitnya ("9.287" → 9287). */
    private function berisi(Builder|\Illuminate\Database\Query\Builder $q, string $k, string $teks, string $bool): void
    {
        if (in_array($k, ['total', 'sisa'], true)) {
            $digit = preg_replace('/\D/', '', $teks);
            if ($digit === '') {
                return;
            }
            $q->whereRaw('CAST(ROUND('.self::ekspresi($k).') AS CHAR) LIKE ?', ['%'.$digit.'%'], $bool);

            return;
        }
        $q->whereRaw('LOWER('.self::ekspresi($k).') LIKE ?', ['%'.mb_strtolower($teks).'%'], $bool);
    }

    /** Kolom bernilai salah satu yang dicentang. Nominal dicentang dalam bentuk "Rp 1.234,5". */
    private function persis(Builder $q, string $k, array $nilai): void
    {
        if (in_array($k, ['total', 'sisa'], true)) {
            $angka = array_values(array_filter(array_map([self::class, 'angkaDariTampilan'], $nilai), fn ($v) => $v !== null));
            $q->where(function ($w) use ($k, $angka) {
                $w->whereRaw('1=0');
                foreach ($angka as $a) {
                    $w->orWhereRaw(self::ekspresi($k).' = ?', [$a]);
                }
            });

            return;
        }
        $q->whereIn(DB::raw(self::ekspresi($k)), array_map('strval', $nilai));
    }

    /** "Rp 9.287.000,5" → 9287000.5 */
    public static function angkaDariTampilan(string $v): ?float
    {
        $s = str_replace(['Rp', ' ', "\u{a0}", '.'], '', $v);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    /** Bentuk tampilan nominal yang sama dengan layar (toLocaleString id-ID, maks. 3 desimal). */
    public static function rp(float $n): string
    {
        $s = number_format($n, 3, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');

        return 'Rp '.$s;
    }

    /**
     * Ringkasan atas seluruh hasil saringan.
     *
     * @return array{open:float, dueNow:float, notDue:float, count_open:int, count_total:int}
     */
    public function ringkasan(Builder $q): array
    {
        $hari = now()->toDateString();
        $r = (clone $q)->toBase()->selectRaw(
            "COUNT(*) AS total,
             SUM(bs_ap_invoices.status='open') AS jml_open,
             COALESCE(SUM(CASE WHEN bs_ap_invoices.status='open' THEN ".self::SISA." END),0) AS sisa_open,
             COALESCE(SUM(CASE WHEN bs_ap_invoices.status='open' AND (bs_ap_invoices.due_date IS NULL OR bs_ap_invoices.due_date <= ?) THEN ".self::SISA." END),0) AS jatuh,
             COALESCE(SUM(CASE WHEN bs_ap_invoices.status='open' AND bs_ap_invoices.due_date > ? THEN ".self::SISA.' END),0) AS belum',
            [$hari, $hari],
        )->first();

        return [
            'open' => round((float) $r->sisa_open, 2),
            'dueNow' => round((float) $r->jatuh, 2),
            'notDue' => round((float) $r->belum, 2),
            'count_open' => (int) $r->jml_open,
            'count_total' => (int) $r->total,
        ];
    }

    /**
     * Isi dropdown tiap kolom — dari SELURUH invoice jenis ini, lepas dari saringan lain
     * (sama seperti sebelumnya), supaya memilih satu kolom tidak mengosongkan kolom lain.
     *
     * @return array<string, array<int,string>>
     */
    public function opsi(): array
    {
        $hasil = [];
        foreach (self::KOLOM as $k) {
            // Kolom tanggal diurutkan menurut TANGGALNYA (terbaru dulu), bukan abjad labelnya.
            $kolomTgl = ['tgl' => 'invoice_date', 'terima' => 'receive_date', 'berkasFinance' => 'berkas_finance_date'][$k] ?? null;
            $vals = $this->dasar()->toBase()
                ->selectRaw(self::ekspresi($k).' AS v')->groupByRaw('v')
                ->orderByRaw($kolomTgl ? "MAX(bs_ap_invoices.$kolomTgl) DESC" : 'v')
                ->limit(self::BATAS_OPSI)->pluck('v');
            $hasil[$k] = in_array($k, ['total', 'sisa'], true)
                ? $vals->map(fn ($v) => self::rp((float) $v))->values()->all()
                : $vals->map(fn ($v) => (string) $v)->values()->all();
        }

        return $hasil;
    }

    /** Kolom yang dipilih untuk baris daftar. */
    public static function kolomBaris(): array
    {
        return ['bs_ap_invoices.id', 'bs_ap_invoices.invoice_number', 'bs_ap_invoices.invoice_date', 'bs_ap_invoices.receive_date', 'bs_ap_invoices.due_date', 'bs_ap_invoices.supplier_id', 'bs_ap_invoices.customer_id',
            'bs_ap_invoices.source_type', 'bs_ap_invoices.source_id', 'bs_ap_invoices.refund_source', 'bs_ap_invoices.description', 'bs_ap_invoices.berkas_notes', 'bs_ap_invoices.berkas_finance_date',
            'bs_ap_invoices.total', 'bs_ap_invoices.net_payable', 'bs_ap_invoices.paid_amount', 'bs_ap_invoices.status',
            'bs_ap_invoices.journal_id', DB::raw(self::STATUS_JURNAL.' AS status_jurnal'), DB::raw('s.name AS nama_supplier'), DB::raw('c.full_name AS nama_customer')];
    }
}
