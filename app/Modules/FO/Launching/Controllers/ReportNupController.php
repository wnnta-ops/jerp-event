<?php

namespace App\Modules\FO\Launching\Controllers;

use App\Filament\Fo\Pages\ReportNup;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Export Excel Report NUP (.xls tabel HTML — pola sama dgn Report Xendit).
 * Filter dioper dari halaman Filament lewat query string, dan datanya memakai
 * ReportNup::filteredQuery agar isinya IDENTIK dengan yang tampil di layar.
 */
class ReportNupController
{
    public function excel(Request $request): Response
    {
        $v = $request->validate([
            'status' => ['nullable', 'in:MENUNGGU_BAYAR,PAID,APPROVED,PENDING,REJECTED,REFUNDED'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = ReportNup::filteredQuery($v['status'] ?? null, $v['from'] ?? null, $v['to'] ?? null, $v['q'] ?? null)
            ->latest('id')->get();

        $e = fn ($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
        $n = fn ($x) => number_format((float) $x, 0, ',', '.');
        $tglId = fn (?string $ymd) => $ymd ? Carbon::parse($ymd)->translatedFormat('d F Y') : null;

        $periode = ($tglId($v['from'] ?? null) ?? 'Awal').' s/d '.($tglId($v['to'] ?? null) ?? 'Sekarang');
        $now = now()->locale('id');
        $dihasilkan = 'Laporan dihasilkan pada hari '.$now->translatedFormat('l, d F Y H:i:s')
            .' oleh user '.($request->user()?->name ?? '-');

        $h = '<h3>REPORT NUP — REKAP PENDAFTARAN</h3>'
            .'<p>Periode Daftar : '.$e($periode).'<br>'
            .'Status Verifikasi : '.$e(($v['status'] ?? '') ?: 'Semua').'<br>'
            .$e($dihasilkan).'</p>';

        $th = 'style="background:#1d70c9;color:#fff"';
        $h .= '<table border="1"><tr>'
            .'<th '.$th.'>No.</th><th '.$th.'>Tgl Daftar</th><th '.$th.'>No NUP</th><th '.$th.'>Customer</th><th '.$th.'>No. Telp</th>'
            .'<th '.$th.'>Sales</th><th '.$th.'>Agent</th><th '.$th.'>Cara Bayar</th><th '.$th.'>Bank</th>'
            .'<th '.$th.'>Pilihan Unit</th><th '.$th.'>Ref Xendit</th><th '.$th.'>Nilai UTJ</th>'
            .'<th '.$th.'>Transfer Xendit</th><th '.$th.'>Selisih</th><th '.$th.'>Status</th>'
            .'<th '.$th.'>Tgl Bayar</th><th '.$th.'>Pencocokan</th>'
            .'</tr>';

        $totalLunas = 0.0;
        foreach ($rows as $i => $r) {
            $lunas = $r->isUtjPaid();
            if ($lunas) {
                $totalLunas += (float) $r->utj_amount;
            }
            $selisih = $r->xenditSelisih();

            $h .= '<tr>'
                .'<td>'.($i + 1).'</td>'
                .'<td>'.$e($r->created_at?->format('d/m/Y H:i')).'</td>'
                .'<td>'.$e($r->no_nup ?: '-').'</td>'
                .'<td>'.$e($r->customer?->full_name ?: $r->nama_pendaftar).'</td>'
                .'<td>'.$e($r->phone ?: ($r->customer?->mobile ?: '-')).'</td>'
                .'<td>'.$e($r->ref_sales_name ?: '-').'</td>'
                .'<td>'.$e($r->agent?->name ?: '-').'</td>'
                .'<td>'.$e($r->cara_bayar ?: '-').'</td>'
                .'<td>'.$e($r->bank?->nama ?: ($r->bank_nama ?: '-')).'</td>'
                .'<td>'.$e(ReportNup::pilihanUnit($r)).'</td>'
                .'<td>'.$e($r->xendit_external_id ?: '-').'</td>'
                .'<td style="mso-number-format:\'#,##0\'">'.$n($r->utj_amount).'</td>'
                .'<td style="mso-number-format:\'#,##0\'">'.($r->xendit_paid_amount !== null ? $n($r->xendit_paid_amount) : '-').'</td>'
                .'<td style="mso-number-format:\'#,##0\'">'.($selisih === null ? '-' : $n($selisih)).'</td>'
                .'<td>'.$e($lunas ? 'LUNAS' : ($r->verif_status ?: '-')).'</td>'
                .'<td>'.$e($r->xendit_paid_at?->format('d/m/Y H:i') ?: '-').'</td>'
                .'<td>'.$e($r->reconStatus()).'</td>'
                .'</tr>';
        }

        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="11">TOTAL NOMINAL MASUK</td>'
            .'<td>'.$n($totalLunas).'</td><td colspan="5">'.count($rows).' pendaftaran</td></tr>'
            .'</table>';

        $file = 'Report-NUP-'.(($v['from'] ?? null) ?: 'awal').'-sd-'.(($v['to'] ?? null) ?: 'sekarang').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }
}
