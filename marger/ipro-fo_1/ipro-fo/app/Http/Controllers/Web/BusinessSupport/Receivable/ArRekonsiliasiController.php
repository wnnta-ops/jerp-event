<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArUmCompensation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Rekonsiliasi Piutang — menyandingkan tagihan dengan uang muka yang sudah dikompensasi,
 * dikelompokkan per SPR.
 *
 *   Sisa Piutang = Σ Invoice Piutang (sisa berjalan) − Σ UM Penjualan terkompensasi
 *
 * Pengelompokan memakai booking (SPR), bukan customer: satu customer bisa memegang beberapa unit,
 * dan menggabungkannya akan menutupi unit mana yang sebenarnya masih kurang bayar. Baris tanpa SPR
 * (invoice denda/saldo awal, atau UM yang bookingnya tak terlacak) tetap ditampilkan dalam grup
 * "Tanpa SPR" alih-alih disembunyikan — kalau disembunyikan, angkanya hilang diam-diam.
 */
class ArRekonsiliasiController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $search = trim((string) $request->input('search', ''));
        $sisa   = (string) $request->input('sisa', ''); // '' | 'ada' | 'lunas'

        $rows = $this->groups()
            ->when($search !== '', fn (Collection $c) => $c->filter(
                fn (array $g) => str_contains(mb_strtolower(implode(' ', [
                    $g['spr'] ?? '', $g['unit'] ?? '', $g['customer'] ?? '',
                ])), mb_strtolower($search))
            ))
            ->when($sisa === 'ada', fn (Collection $c) => $c->filter(fn (array $g) => $g['sisa'] > 0.009))
            ->when($sisa === 'lunas', fn (Collection $c) => $c->filter(fn (array $g) => $g['sisa'] <= 0.009))
            ->values();

        return Inertia::render('BusinessSupport/Receivable/Rekonsiliasi', [
            'rows'    => $rows,
            'filters' => ['search' => $search ?: null, 'sisa' => $sisa ?: null],
            'summary' => [
                'count'   => $rows->count(),
                'invoice' => round((float) $rows->sum('invoice_total'), 2),
                'um'      => round((float) $rows->sum('um_total'), 2),
                'sisa'    => round((float) $rows->sum('sisa'), 2),
            ],
        ]);
    }

    /** Surat tagihan untuk grup SPR terpilih — 1 halaman per SPR. */
    public function printTagihan(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $keys = $this->selectedKeys($request);
        abort_if($keys === [], 400, 'Belum ada baris yang dicentang.');

        $pilih = $this->groups()->whereIn('row_key', $keys)->values();
        abort_if($pilih->isEmpty(), 404, 'Data tidak ditemukan.');

        return $this->html(
            'Surat Tagihan ('.$pilih->count().')',
            $pilih->map(fn (array $g) => $this->tagihanSheet($g))->implode('')
        );
    }

    // ── Penyusunan data ──────────────────────────────────────────────────────────────────────

    /**
     * Satu grup = satu SPR (atau grup "Tanpa SPR").
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function groups(): Collection
    {
        $invoices = BsArInvoice::query()
            ->whereNull('deleted_at')
            ->with(['customer:id,full_name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code'])
            ->get();

        $ums = BsArUmCompensation::query()
            ->with(['customer:id,full_name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code'])
            ->get();

        /** @var array<string, array<string, mixed>> $grup */
        $grup = [];
        // Kunci grup WAJIB memuat customer saat SPR tidak ada. Kalau semua baris tanpa SPR
        // dilempar ke satu kunci global, piutang banyak customer menyatu dalam satu baris:
        // UM customer A akan menutup tagihan customer B, dan surat tagihan tercetak atas nama
        // customer pertama tetapi berisi tagihan customer lain. Karena booking_id nullable,
        // SELURUH invoice lama masuk jalur ini — jadi kasusnya bukan langka, melainkan default.
        $kunci = function (?int $bookingId, ?int $customerId): string {
            if ($bookingId) {
                return 'spr-'.$bookingId;   // SPR sudah menentukan customer-nya
            }

            return $customerId ? 'cust-'.$customerId : 'tanpa-customer';
        };

        $siapkan = function (string $key, ?int $bookingId, $booking, $customer) use (&$grup): void {
            if (isset($grup[$key])) {
                return;
            }
            $grup[$key] = [
                'row_key'       => $key,
                'booking_id'    => $bookingId,
                'spr'           => $booking?->booking_number,
                'unit'          => $booking?->unit?->unit_code,
                'customer'      => $customer?->full_name,
                'invoice_total' => 0.0,
                'invoice_count' => 0,
                'um_total'      => 0.0,
                'um_count'      => 0,
                'invoices'      => [],
                'ums'           => [],
            ];
        };

        foreach ($invoices as $inv) {
            $key = $kunci($inv->booking_id, $inv->customer_id);
            $siapkan($key, $inv->booking_id, $inv->booking, $inv->customer);
            // Yang direkonsiliasi adalah SISA tagihan (sudah dikurangi pembayaran), bukan nilai
            // penuh invoice — kalau memakai total, pembayaran yang masuk lewat kuitansi tidak
            // terlihat dan sisa piutang jadi kelebihan hitung.
            $sisaInv = round((float) $inv->outstanding, 2);
            $grup[$key]['invoice_total'] += $sisaInv;
            $grup[$key]['invoice_count']++;
            $grup[$key]['invoices'][] = [
                'id'          => $inv->id,
                'number'      => $inv->invoice_number,
                'date'        => optional($inv->invoice_date)->toDateString(),
                'description' => $inv->description,
                'total'       => (float) $inv->total,
                'paid'        => (float) $inv->paid_amount,
                'outstanding' => $sisaInv,
            ];
            if (! $grup[$key]['customer']) {
                $grup[$key]['customer'] = $inv->customer?->full_name;
            }
        }

        foreach ($ums as $um) {
            $key = $kunci($um->booking_id, $um->customer_id);
            $siapkan($key, $um->booking_id, $um->booking, $um->customer);
            $nilai = round((float) $um->amount, 2);
            $grup[$key]['um_total'] += $nilai;
            $grup[$key]['um_count']++;
            $grup[$key]['ums'][] = [
                'id'           => $um->id,
                'source_type'  => $um->source_type,
                'source_label' => $um->source_label,
                'date'         => optional($um->compensated_at)->toDateString(),
                'note'         => $um->note,
                'amount'       => $nilai,
            ];
            if (! $grup[$key]['customer']) {
                $grup[$key]['customer'] = $um->customer?->full_name;
            }
        }

        return collect($grup)
            ->map(function (array $g): array {
                $g['invoice_total'] = round($g['invoice_total'], 2);
                $g['um_total']      = round($g['um_total'], 2);
                $g['sisa']          = round($g['invoice_total'] - $g['um_total'], 2);

                return $g;
            })
            // "Tanpa SPR" ditaruh paling bawah; sisanya urut nomor SPR.
            ->sortBy(fn (array $g) => ($g['booking_id'] ? '0' : '1').($g['spr'] ?? 'zzz'))
            ->values();
    }

    /** Menerima ?keys=a,b maupun ?keys[]=a&keys[]=b. */
    private function selectedKeys(Request $request): array
    {
        $raw  = $request->input('keys');
        $keys = is_array($raw) ? $raw : array_filter(explode(',', (string) $raw), fn ($v) => trim($v) !== '');

        return array_values(array_unique(array_map('strval', $keys)));
    }

    // ── Cetak surat tagihan ──────────────────────────────────────────────────────────────────

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
            .'.title{text-align:center;font-size:18px;font-weight:bold;letter-spacing:1px}'
            .'.meta{width:100%;margin:10px 0 12px}.meta td{padding:2px 0;vertical-align:top}'
            .'.meta td.k{width:110px}'
            .'.items{width:100%;margin-top:6px}'
            .'.items th,.items td{border:1px solid #9aa0a6;padding:5px 6px;vertical-align:top}'
            .'.items th{background:#e8e8e8;text-align:center;font-weight:bold}'
            .'.items td.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
            .'.sub{background:#f3f4f6;font-weight:bold}'
            .'.grand{background:#e8e8e8;font-weight:bold;font-size:12px}'
            .'.para{margin:10px 0;line-height:1.6;text-align:justify}'
            .'.sign{margin-top:24px;width:100%}.sign td{vertical-align:top}'
            .'.sign .nm{font-weight:bold;text-decoration:underline}'
            .'@media print{.noprint{display:none}}';
    }

    /** Satu lembar surat tagihan untuk satu SPR. */
    private function tagihanSheet(array $g): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $nama    = (string) config('business_support.sales_invoice.seller_name', config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO'));
        $alamat  = (string) config('business_support.sales_invoice.seller_address', config('business_support.company.address', ''));
        $signer  = (string) config('business_support.sales_invoice.signer_name', '');
        $jabatan = (string) config('business_support.sales_invoice.signer_title', 'Manager Finance');

        $baris = '';
        foreach ($g['invoices'] as $i) {
            $baris .= '<tr><td>'.$e($i['number']).'</td><td>'.$e($i['date']).'</td>'
                .'<td>'.$e($i['description']).'</td><td class="r">'.$rp($i['outstanding']).'</td></tr>';
        }
        if ($baris === '') {
            $baris = '<tr><td colspan="4" style="text-align:center;color:#6b7280">Tidak ada tagihan piutang</td></tr>';
        }
        $baris .= '<tr class="sub"><td colspan="3" class="r">Sub Total Tagihan</td><td class="r">'.$rp($g['invoice_total']).'</td></tr>';

        foreach ($g['ums'] as $u) {
            $baris .= '<tr><td>'.$e($u['source_label']).'</td><td>'.$e($u['date']).'</td>'
                .'<td>'.$e($u['note'] ?: 'UM Penjualan dikompensasi').'</td><td class="r">('.$rp($u['amount']).')</td></tr>';
        }
        $baris .= '<tr class="sub"><td colspan="3" class="r">Sub Total UM Penjualan</td><td class="r">('.$rp($g['um_total']).')</td></tr>'
            .'<tr class="grand"><td colspan="3" class="r">SISA PIUTANG</td><td class="r">'.$rp($g['sisa']).'</td></tr>';

        return '<section class="sheet">'
            .'<table class="hdr"><tr>'
            .'<td style="width:70px"><img class="logo" src="/images/mup-logo.jpeg" alt=""></td>'
            .'<td><div style="font-weight:bold;font-size:13px">'.$e($nama).'</div>'
            .'<div style="font-size:10px;color:#4b5563">'.$e($alamat).'</div></td>'
            .'</tr></table>'
            .'<div class="title">SURAT TAGIHAN</div>'
            .'<table class="meta">'
            .'<tr><td class="k">Kepada Yth.</td><td>: '.$e($g['customer'] ?: '—').'</td></tr>'
            .'<tr><td class="k">No. SPR</td><td>: '.$e($g['spr'] ?: '—').'</td></tr>'
            .'<tr><td class="k">Unit</td><td>: '.$e($g['unit'] ?: '—').'</td></tr>'
            .'</table>'
            .'<div class="para">Dengan hormat, berikut kami sampaikan rincian kewajiban pembayaran Bapak/Ibu '
            .'per '.now()->translatedFormat('d F Y').'. Uang muka yang telah dikompensasi sudah kami perhitungkan sebagai pengurang.</div>'
            .'<table class="items"><thead><tr>'
            .'<th style="width:150px">Dokumen</th><th style="width:90px">Tanggal</th><th>Keterangan</th><th style="width:130px">Jumlah</th>'
            .'</tr></thead><tbody>'.$baris.'</tbody></table>'
            .'<div class="para">Mohon pembayaran dilakukan sesuai ketentuan yang berlaku. Abaikan surat ini bila pembayaran telah dilakukan.</div>'
            .'<table class="sign"><tr><td style="width:60%"></td><td>'
            .'Sidoarjo, '.now()->translatedFormat('d F Y').'<br><br><br><br>'
            .'<span class="nm">'.$e($signer).'</span><br>'.$e($jabatan)
            .'</td></tr></table>'
            .'</section>';
    }
}
