<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cetak dokumen Account Receivable: bukti piutang (per invoice) & surat tagihan (per SPR).
 *
 * Dipisah dari ArInvoiceController yang sudah gemuk, dan karena Rekonsiliasi Piutang memakai
 * pembangun lembar yang sama. Mengikuti pola cetak yang berlaku di repo (lihat
 * SalesInvoiceController / CashBookController): HTML dirakit di PHP, A4, auto-print via browser —
 * bukan Blade, bukan Browsershot.
 */
class ArPrintController extends Controller
{
    /** Cetak satu bukti piutang. */
    public function invoice(Request $request, BsArInvoice $invoice): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $invoice->load(['customer', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code,unit_type_id', 'booking.unit.unitType:id,name']);

        return $this->html($invoice->invoice_number, $this->invoiceSheet($invoice));
    }

    /** Cetak banyak bukti piutang sekaligus — 1 invoice per halaman. */
    public function invoiceBulk(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $ids = $this->selectedIds($request);
        abort_if($ids === [], 400, 'Belum ada baris yang dicentang.');

        $rows = BsArInvoice::whereIn('id', $ids)
            ->with(['customer', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code,unit_type_id', 'booking.unit.unitType:id,name'])
            ->orderBy('invoice_date')->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404, 'Data tidak ditemukan.');

        return $this->html(
            'Bukti Piutang ('.$rows->count().')',
            $rows->map(fn (BsArInvoice $r) => $this->invoiceSheet($r))->implode('')
        );
    }

    // ── Bantu ────────────────────────────────────────────────────────────────────────────────

    /** Menerima ?ids=1,2,3 maupun ?ids[]=1&ids[]=2 (pola sama dengan layar cetak massal lain). */
    private function selectedIds(Request $request): array
    {
        $raw = $request->input('ids');
        $ids = is_array($raw) ? $raw : array_filter(explode(',', (string) $raw), fn ($v) => trim($v) !== '');

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function html(string $title, string $inner): Response
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $doc = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($title).'</title>'
            .'<style>'.$this->css().'</style></head><body>'.$inner
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';

        return response($doc)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function css(): string
    {
        return '@page{size:A4;margin:14mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:11px;color:#1f2937;margin:0}'
            .'table{border-collapse:collapse}'
            .'.sheet{page-break-after:always}.sheet:last-of-type{page-break-after:auto}'
            .'.hdr{width:100%;margin-bottom:14px}.hdr td{vertical-align:middle}'
            .'.logo{width:64px;height:64px;object-fit:contain}'
            .'.title{text-align:center;font-size:20px;font-weight:bold;letter-spacing:1px}'
            .'.meta{width:100%;margin-bottom:12px}.meta td{padding:2px 0;vertical-align:top;font-size:11px}'
            .'.meta td.k{width:120px}.meta td.v{padding-left:4px}'
            .'.items{width:100%;margin-top:6px;font-size:11px}'
            .'.items th,.items td{border:1px solid #9aa0a6;padding:5px 6px;vertical-align:top}'
            .'.items th{background:#e8e8e8;text-align:center;font-weight:bold}'
            .'.items td.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
            .'.items td.c{text-align:center}'
            .'.grand{font-weight:bold;background:#f3f4f6}'
            .'.note{margin-top:10px;font-size:10px;color:#4b5563}'
            .'.sign{margin-top:24px;width:100%}.sign td{vertical-align:top;font-size:11px}'
            .'.sign .nm{font-weight:bold;text-decoration:underline}'
            .'@media print{.noprint{display:none}}';
    }

    private function company(): array
    {
        return [
            'name'    => (string) config('business_support.sales_invoice.seller_name', config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO')),
            'address' => (string) config('business_support.sales_invoice.seller_address', config('business_support.company.address', '')),
            'signer'  => (string) config('business_support.sales_invoice.signer_name', ''),
            'title'   => (string) config('business_support.sales_invoice.signer_title', 'Manager Finance'),
        ];
    }

    /** Kop surat yang dipakai bersama oleh bukti piutang & surat tagihan. */
    private function header(string $judul): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $c = $this->company();

        return '<table class="hdr"><tr>'
            .'<td style="width:70px"><img class="logo" src="/images/mup-logo.jpeg" alt=""></td>'
            .'<td><div style="font-weight:bold;font-size:13px">'.$e($c['name']).'</div>'
            .'<div style="font-size:10px;color:#4b5563">'.$e($c['address']).'</div></td>'
            .'</tr></table><div class="title">'.$e($judul).'</div>';
    }

    private function signature(string $kota = 'Sidoarjo'): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $c = $this->company();

        return '<table class="sign"><tr><td style="width:60%"></td><td>'
            .$e($kota).', '.now()->translatedFormat('d F Y').'<br><br><br><br>'
            .'<span class="nm">'.$e($c['signer']).'</span><br>'.$e($c['title'])
            .'</td></tr></table>';
    }

    /** Satu lembar bukti piutang. */
    private function invoiceSheet(BsArInvoice $r): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $unit = $r->booking?->unit;

        $baris = '<tr><td>'.$e($r->description ?: 'Tagihan piutang').'</td>'
            .'<td class="r">'.$rp($r->dpp).'</td>'
            .'<td class="r">'.$rp($r->ppn_amount).'</td>'
            .'<td class="r">'.$rp($r->total).'</td></tr>';

        return '<section class="sheet">'
            .$this->header('BUKTI PIUTANG')
            .'<table class="meta">'
            .'<tr><td class="k">No. Invoice</td><td class="v">: '.$e($r->invoice_number).'</td>'
            .'<td class="k">Tanggal</td><td class="v">: '.$e(optional($r->invoice_date)->translatedFormat('d F Y')).'</td></tr>'
            .'<tr><td class="k">Customer</td><td class="v">: '.$e($r->customer?->full_name).'</td>'
            .'<td class="k">Jatuh Tempo</td><td class="v">: '.$e(optional($r->due_date)->translatedFormat('d F Y') ?: '—').'</td></tr>'
            .'<tr><td class="k">No. SPR</td><td class="v">: '.$e($r->booking?->booking_number ?: '—').'</td>'
            .'<td class="k">Unit</td><td class="v">: '.$e($unit?->unit_code ?: '—').($unit?->unitType?->name ? ' ('.$e($unit->unitType->name).')' : '').'</td></tr>'
            .'</table>'
            .'<table class="items"><thead><tr><th>Keterangan</th><th style="width:110px">DPP</th><th style="width:100px">PPN</th><th style="width:120px">Total</th></tr></thead>'
            .'<tbody>'.$baris
            .'<tr class="grand"><td class="r">Total Tagihan</td><td class="r">'.$rp($r->dpp).'</td><td class="r">'.$rp($r->ppn_amount).'</td><td class="r">'.$rp($r->total).'</td></tr>'
            .'<tr><td class="r">Sudah Dibayar</td><td colspan="3" class="r">'.$rp($r->paid_amount).'</td></tr>'
            .'<tr class="grand"><td class="r">Sisa Piutang</td><td colspan="3" class="r">'.$rp($r->outstanding).'</td></tr>'
            .'</tbody></table>'
            .$this->signature()
            .'</section>';
    }
}
