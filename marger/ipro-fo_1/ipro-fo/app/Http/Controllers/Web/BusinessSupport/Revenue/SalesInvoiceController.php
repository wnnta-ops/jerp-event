<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Revenue;

use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoice Penjualan (Sales Invoice) — cetak massal dari rincian yang SUDAH Booked Revenue.
 *
 * Sumber baris = bs_booked_revenues (itulah arti "sudah booked revenue" di sidebar: barisnya
 * sudah ada). Yang BATAL sengaja dikeluarkan — booking yang dibatalkan jurnalnya sudah dibalik,
 * jadi tidak boleh ditagihkan.
 *
 * Tanggal invoice = booking_date, yaitu kolom "Tanggal" pada layar Sudah Booked Revenue. BUKAN
 * recognized_at: itu cuma stempel jam saat tombol "Akui" diklik (now()), tidak diisi user, dan
 * NULL untuk baris yang masih berstatus booked.
 *
 * Angka mengikuti template resmi "Invoice Penjualan.xlsx" (PPN 2025, DPP Nilai Lain):
 *   Harga Gross      = gross_price / 1,11   (gross_price disimpan TERMASUK PPN 11%)
 *   Diskon Penjualan = discount / 1,11
 *   Harga Nett       = Gross − Diskon        (≡ bs_booked_revenues.amount, nilai revenue di GL)
 *   Total DPP        = Nett × 11/12
 *   PPN 12%          = DPP × 12%
 *   Grand Total      = Nett + PPN
 */
class SalesInvoiceController extends Controller
{
    /** Pembagi harga bruto → nilai sebelum PPN. Sama dengan BookedRevenueService::makeRecord(). */
    private const PPN_INCL_DIVISOR = 1.11;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        // Paginasi seperti layar Sudah Booked Revenue — daftarnya tumbuh seiring unit terjual.
        $page = $this->withRelations($this->query($request))->paginate(50)->withQueryString();

        // Nomor SPR di-resolve SEKALI untuk seluruh halaman (bukan per baris) → bukan N+1.
        $spr = $this->sprMap($page->getCollection());
        $page->getCollection()->transform(fn (BsBookedRevenue $r) => $this->row($r, $spr[$this->pairKey($r)] ?? null));

        // Ringkasan atas SELURUH hasil filter, bukan cuma halaman ini.
        $all = $this->query($request)->get(['gross_price', 'discount']);

        return Inertia::render('BusinessSupport/BookedRevenue/InvoicePenjualan', [
            'rows'    => $page,
            'filters' => $request->only(['search', 'status']),
            'summary' => [
                'count' => $all->count(),
                'total' => round($all->sum(fn (BsBookedRevenue $r) => $this->amounts($r)['grand']), 2),
            ],
        ]);
    }

    /**
     * Ekspor Invoice Penjualan ke Excel.
     *
     * Memakai saringan yang SAMA dengan layar tetapi TANPA paginasi: berkas yang berisi 50
     * baris pertama akan terbaca sebagai "memang segitu datanya", dan jumlahnya tidak akan
     * cocok dengan kartu ringkasan yang menghitung seluruh hasil filter.
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $rows = $this->withRelations($this->query($request))->get();
        $spr  = $this->sprMap($rows);

        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        // mso-number-format '\@' memaksa Excel membaca kolom identitas sebagai teks — tanpa
        // itu No. Invoice/No. SPR yang panjang berubah jadi notasi ilmiah.
        $t  = fn ($v) => '<td style="mso-number-format:\'\@\'">'.$e($v).'</td>';
        $n  = fn ($v) => '<td>'.number_format((float) ($v ?? 0), 2, ',', '.').'</td>';
        $label = ['booked' => 'Booked', 'recognized' => 'Diakui'];

        $h = '<table border="1"><tr style="background:#c2410c;color:#fff">'
            .'<th>No</th><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Unit / Deskripsi</th>'
            .'<th>No. SPR</th><th>No. BR</th><th>Status</th><th>Harga Nett</th><th>PPN</th><th>Grand Total</th></tr>';

        $no = 0;
        $grand = 0.0;
        foreach ($rows as $r) {
            $b = $this->row($r, $spr[$this->pairKey($r)] ?? null);
            $no++;
            $grand += (float) $b['grand_total'];
            $h .= '<tr>'.$t($no).$t($b['invoice_number']).$t($b['invoice_date']).$t($b['customer'])
                .$t($b['unit_desc'] ?: $b['unit']).$t($b['spr']).$t($b['br_number'])
                .$t($label[$b['status']] ?? $b['status'])
                .$n($b['nett']).$n($b['ppn']).$n($b['grand_total'])
                .'</tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="10">TOTAL — '.$no.' unit</td>'
            .$n($grand).'</tr></table>';

        $file = 'Invoice-Penjualan-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>INVOICE PENJUALAN</h3>'
            .($request->input('search') ? '<p>Pencarian: '.$e($request->input('search')).'</p>' : '')
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Cetak massal — 1 invoice per halaman, dalam satu dokumen. */
    public function printBulk(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $ids = $this->selectedIds($request);
        abort_if($ids === [], 400, 'Belum ada baris yang dicentang.');

        $rows = $this->withRelations($this->baseQuery())
            ->whereIn('id', $ids)
            ->orderBy('booking_date')->orderBy('id')
            ->get();
        abort_if($rows->isEmpty(), 404, 'Data tidak ditemukan (atau sudah dibatalkan).');

        $inner = $rows->map(fn (BsBookedRevenue $r) => $this->invoiceSheet($r))->implode('');

        return response($this->invoiceDoc('Invoice Penjualan ('.$rows->count().')', $inner))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Cetak satu invoice — memakai pembangun lembar yang SAMA dengan cetak massal. */
    public function print(Request $request, BsBookedRevenue $bookedRevenue): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);
        abort_if($bookedRevenue->isCancelled(), 404, 'Booked Revenue ini sudah dibatalkan.');

        $bookedRevenue->load(['customer', 'unit.unitType', 'unit.project']);

        return response($this->invoiceDoc($this->invoiceNumber($bookedRevenue), $this->invoiceSheet($bookedRevenue)))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Baris yang boleh ditagihkan: sudah booked revenue & TIDAK dibatalkan.
     * TANPA eager-load — relasinya ditambahkan di pemanggil yang memang butuh (lihat withRelations).
     * Eager-load di sini akan meledak pada query ringkasan yang hanya memilih beberapa kolom:
     * memuat relasi customer menuntut customer_id ikut ter-select (Model::shouldBeStrict aktif).
     */
    private function baseQuery()
    {
        return BsBookedRevenue::query()->where('status', '!=', 'cancelled');
    }

    /** Relasi yang dibaca invoiceSheet()/unitDescription()/buyerAddress(). */
    private function withRelations($q)
    {
        return $q->with(['customer', 'unit.unitType', 'unit.project']);
    }

    private function query(Request $request)
    {
        return $this->baseQuery()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('br_number', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%"))
                ->orWhereHas('unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->latest('booking_date')->latest('id');
    }

    /** Baris tabel (nilai sudah dihitung server agar layar & cetakan mustahil berbeda). */
    private function row(BsBookedRevenue $r, ?string $spr): array
    {
        $m = $this->amounts($r);

        return [
            'id'             => $r->id,
            'br_number'      => $r->br_number,
            'invoice_number' => $this->invoiceNumber($r),
            'invoice_date'   => $r->booking_date?->toDateString(),
            'customer'       => $r->customer?->full_name,
            'unit'           => $r->unit?->unit_code,
            'unit_desc'      => $this->unitDescription($r),
            'spr'            => $spr,
            'status'         => $r->status,
            'gross'          => $m['gross'],
            'discount'       => $m['discount'],
            'nett'           => $m['nett'],
            'dpp'            => $m['dpp'],
            'ppn'            => $m['ppn'],
            'grand_total'    => $m['grand'],
        ];
    }

    /**
     * Rumus persis template. Dihitung dari gross_price & discount (bukan dari `amount`) supaya
     * urutan pembulatannya sama dengan Excel; hasil Nett tetap ≡ `amount` (selisih < 1 sen).
     *
     * @return array{gross:float, discount:float, nett:float, dpp:float, ppn:float, grand:float}
     */
    private function amounts(BsBookedRevenue $r): array
    {
        $ppnPct   = (float) config('business_support.sales_invoice.ppn_pct', 12);
        $gross    = (float) $r->gross_price / self::PPN_INCL_DIVISOR;
        $discount = (float) $r->discount / self::PPN_INCL_DIVISOR;
        $nett     = $gross - $discount;
        $dpp      = $nett * 11 / 12;          // DPP Nilai Lain
        $ppn      = $dpp * $ppnPct / 100;

        return [
            'gross'    => round($gross, 2),
            'discount' => round($discount, 2),
            'nett'     => round($nett, 2),
            'dpp'      => round($dpp, 2),
            'ppn'      => round($ppn, 2),
            'grand'    => round($nett + $ppn, 2),
        ];
    }

    /**
     * No. Invoice — disusun dari data unit mengikuti STRUKTUR template resmi
     * ("…-JRK2-XI-10/INV/12/2024"): {kode proyek}-{kode unit}/INV/{bulan}/{tahun}.
     * Awalan "1.2.001" pada contoh berasal dari sistem lama & tidak punya sumber di sini.
     */
    private function invoiceNumber(BsBookedRevenue $r): string
    {
        $proj = $r->unit?->project?->code;
        $unit = $r->unit?->unit_code;
        $head = trim(implode('-', array_filter([$proj, $unit]))) ?: $r->br_number;
        $d    = $r->booking_date;

        return $head.'/INV/'.($d ? $d->format('m/Y') : '');
    }

    private function pairKey(BsBookedRevenue $r): string
    {
        return $r->customer_id.'-'.$r->unit_id;
    }

    /**
     * Nomor SPR (Surat Pemesanan Rumah) = bookings.booking_number, untuk SELURUH baris sekaligus.
     *
     * bs_booked_revenues TIDAK punya booking_id — kaitannya lewat pasangan customer+unit. Pasangan
     * itu bisa berulang (unit yang batal lalu dipesan lagi), jadi diambil booking TERBARU: hasil
     * diurut menaik sehingga penulisan terakhir ke peta = yang paling baru.
     *
     * @param  \Illuminate\Support\Collection<int,BsBookedRevenue>  $rows
     * @return array<string,string>  "customerId-unitId" => booking_number
     */
    private function sprMap($rows): array
    {
        $custIds = $rows->pluck('customer_id')->filter()->unique()->values();
        $unitIds = $rows->pluck('unit_id')->filter()->unique()->values();
        if ($custIds->isEmpty() || $unitIds->isEmpty()) {
            return [];
        }

        $map = [];
        // whereIn per kolom = superset (perkalian silang); penguncian ke pasangan yang benar
        // terjadi saat memetakan — tetap satu query, tanpa N+1.
        foreach (Booking::whereIn('customer_id', $custIds->all())->whereIn('unit_id', $unitIds->all())
            ->orderBy('booking_date')->orderBy('id')
            ->get(['customer_id', 'unit_id', 'booking_number']) as $b) {
            $map[$b->customer_id.'-'.$b->unit_id] = $b->booking_number;
        }

        return $map;
    }

    /** "Rumah <alamat/proyek + unit> - <tipe> - LB. x LT. y" (mengikuti template). */
    private function unitDescription(BsBookedRevenue $r): string
    {
        $unit = $r->unit;
        $type = $unit?->unitType;
        $where = trim((string) ($unit?->address ?: trim(($unit?->project?->name ?? '').' '.($unit?->unit_code ?? ''))));

        $parts = array_filter([
            $where !== '' ? 'Rumah '.$where : null,
            $type?->name,
            ($type?->lb !== null || $type?->lt !== null)
                ? trim('LB. '.($type?->lb ?? '-').' LT. '.($type?->lt ?? '-'))
                : null,
        ]);

        return implode(' - ', $parts) ?: '—';
    }

    /** Alamat pembeli dirangkai dari komponennya (tidak ada satu kolom alamat utuh). */
    private function buyerAddress(BsBookedRevenue $r): string
    {
        $c = $r->customer;

        return trim(implode(', ', array_filter([
            $c?->address_line, $c?->address_rt_rw, $c?->kelurahan, $c?->kecamatan,
            $c?->city, $c?->province, $c?->postal_code,
        ])), ', ');
    }

    /**
     * Baris yang dicentang (?ids=1,2,3 maupun ?ids[]=1&ids[]=2).
     * Disalin dari CashBookController::selectedIds — versi yang HANYA menerima array akan
     * diam-diam salah membaca "1,2,3" menjadi satu id ngawur.
     */
    private function selectedIds(Request $request): array
    {
        $raw = $request->input('ids');
        $ids = is_array($raw) ? $raw : array_filter(explode(',', (string) $raw), fn ($v) => trim($v) !== '');

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Pembungkus dokumen (CSS + auto-print). $inner = satu atau banyak <section class="sheet">. */
    private function invoiceDoc(string $title, string $inner): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($title).'</title>'
            .'<style>'.$this->invoiceCss().'</style></head><body>'.$inner
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }

    private function invoiceCss(): string
    {
        return '@page{size:A4;margin:14mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:11px;color:#1f2937;margin:0}'
            .'table{border-collapse:collapse}'
            // 1 invoice = 1 halaman; lembar terakhir tidak menyisakan halaman kosong.
            .'.sheet{page-break-after:always}.sheet:last-of-type{page-break-after:auto}'
            .'.hdr{width:100%;margin-bottom:14px}.hdr td{vertical-align:middle}'
            .'.logo{width:64px;height:64px;object-fit:contain}'
            .'.title{text-align:center;font-size:20px;font-weight:bold;letter-spacing:1px}'
            .'.meta{width:100%;margin-bottom:12px}.meta td{padding:2px 0;vertical-align:top;font-size:11px}'
            .'.meta td.k{width:120px}.meta td.v{padding-left:4px}'
            .'.sec{background:#e8e8e8;font-weight:bold;padding:4px 6px;margin:10px 0 4px;border:1px solid #9aa0a6}'
            .'.items{width:100%;margin-top:6px;font-size:11px}'
            .'.items th,.items td{border:1px solid #9aa0a6;padding:5px 6px;vertical-align:top}'
            .'.items th{background:#e8e8e8;text-align:center;font-weight:bold}'
            .'.items td.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
            .'.items td.c{text-align:center}.items td.noborder{border:0}'
            .'.grand{font-weight:bold}'
            .'.sign{margin-top:24px;width:100%}.sign td{vertical-align:top;font-size:11px}'
            .'.sign .nm{font-weight:bold;text-decoration:underline}'
            .'@media print{.noprint{display:none}}';
    }

    /** Satu lembar invoice — tata letaknya mengikuti template resmi. */
    private function invoiceSheet(BsBookedRevenue $r): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 2, ',', '.');
        $cfg = fn (string $k, $d = null) => config("business_support.sales_invoice.{$k}", $d);

        $m      = $this->amounts($r);
        $ppnPct = (float) $cfg('ppn_pct', 12);
        $desc   = $this->unitDescription($r);
        // "31 Januari 2025" — translatedFormat butuh APP_LOCALE=id.
        $tgl    = optional($r->booking_date)->translatedFormat('d F Y') ?: '—';

        $meta = fn (string $k, string $v) => '<tr><td class="k">'.$e($k).'</td><td class="v">: '.$e($v).'</td></tr>';

        $item = fn (string $label, ?string $qty, ?float $harga, ?float $jumlah, bool $bold = false) =>
            '<tr'.($bold ? ' class="grand"' : '').'><td class="c"></td><td>'.$e($label).'</td>'
            .'<td class="c">'.$e($qty ?? '').'</td>'
            .'<td class="r">'.($harga === null ? '' : $rp($harga)).'</td>'
            .'<td class="r">'.($jumlah === null ? '' : $rp($jumlah)).'</td></tr>';

        return '<section class="sheet">'
            .'<table class="hdr"><tr>'
                .'<td style="width:22%"><img class="logo" src="/images/mup-logo.jpeg" alt="MUP"></td>'
                .'<td class="title">SALES INVOICE</td>'
                .'<td style="width:22%"></td>'
            .'</tr></table>'

            .'<table class="meta">'
                .$meta('No. Invoice', $this->invoiceNumber($r))
                .$meta('Tanggal. Invoice', $tgl)
                .$meta('Unit Rumah', $desc)
            .'</table>'

            .'<div class="sec">IDENTITAS PENJUAL</div>'
            .'<table class="meta">'
                .$meta('Nama Penjual', (string) $cfg('seller_name'))
                .$meta('Alamat Penjual', (string) $cfg('seller_address'))
                .$meta('NPWP', (string) $cfg('seller_npwp'))
            .'</table>'

            .'<div class="sec">IDENTITAS PEMBELI</div>'
            .'<table class="meta">'
                .$meta('Nama Pembeli', (string) ($r->customer?->full_name ?: '—'))
                .$meta('Alamat Pembeli', $this->buyerAddress($r) ?: '—')
                .$meta('NPWP', (string) ($r->customer?->npwp ?: '—'))
            .'</table>'

            .'<table class="items">'
                .'<thead><tr><th style="width:6%">No.</th><th>Deskripsi Barang</th>'
                .'<th style="width:10%">Quantity</th><th style="width:18%">Harga</th><th style="width:20%">Jumlah</th></tr></thead>'
                .'<tbody>'
                    .'<tr><td class="c">1</td><td colspan="4">'.$e($desc).'</td></tr>'
                    .'<tr><td class="c"></td><td colspan="4">Rincian Perhitungan sebagai berikut :</td></tr>'
                    .$item('Harga Gross', '1', $m['gross'], $m['gross'])
                    .$item('Diskon Penjualan', '1', $m['discount'], $m['discount'])
                    .'<tr><td class="c"></td><td></td><td></td><td class="r">Harga Nett</td><td class="r">'.$rp($m['nett']).'</td></tr>'
                    .'<tr><td class="c"></td><td></td><td></td><td class="r">Total DPP</td><td class="r">'.$rp($m['dpp']).'</td></tr>'
                    .'<tr><td class="c"></td><td></td><td></td><td class="r">PPN '.$e(rtrim(rtrim(number_format($ppnPct, 2, ',', '.'), '0'), ',')).'%</td><td class="r">'.$rp($m['ppn']).'</td></tr>'
                    .'<tr class="grand"><td class="c"></td><td></td><td></td><td class="r">Grand Total</td><td class="r">'.$rp($m['grand']).'</td></tr>'
                .'</tbody>'
            .'</table>'

            .'<table class="sign"><tr>'
                .'<td style="width:62%"></td>'
                .'<td>Hormat Kami,<div style="height:52px"></div>'
                .'<div class="nm">'.$e((string) $cfg('signer_name')).'</div>'
                .'<div>'.$e((string) $cfg('signer_title')).'</div></td>'
            .'</tr></table>'
            .'</section>';
    }
}
