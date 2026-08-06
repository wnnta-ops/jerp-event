<?php

namespace App\Modules\FO\Launching\Controllers;

use App\Filament\Fo\Pages\ReportXendit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Export Excel Report Xendit (.xls tabel HTML — pola sama dgn Laporan Penjualan).
 * Filter (status/periode/cari) dioper dari halaman Filament via query string,
 * datanya memakai ReportXendit::filteredQuery agar identik dengan layar.
 */
class ReportXenditController
{
    public function excel(Request $request): Response
    {
        $v = $request->validate([
            'status' => ['nullable', 'in:PENDING,PAID,EXPIRED'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = ReportXendit::filteredQuery($v['status'] ?? null, $v['from'] ?? null, $v['to'] ?? null, $v['q'] ?? null)
            ->latest('id')->get();

        $e = fn ($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
        $n = fn ($x) => number_format((float) $x, 0, ',', '.');
        $tglId = fn (?string $ymd) => $ymd ? Carbon::parse($ymd)->translatedFormat('d F Y') : null;

        $periode = ($tglId($v['from'] ?? null) ?? 'Awal').' s/d '.($tglId($v['to'] ?? null) ?? 'Sekarang');
        $now = now()->locale('id');
        $dihasilkan = 'Laporan dihasilkan pada hari '.$now->translatedFormat('l, d F Y H:i:s')
            .' oleh user '.($request->user()?->name ?? '-');

        $h = '<h3>REPORT XENDIT — PENCOCOKAN PEMBAYARAN UTJ</h3>'
            .'<p>Periode Daftar : '.$e($periode).'<br>'
            .'Status : '.$e(($v['status'] ?? '') ?: 'Semua').'<br>'
            .$e($dihasilkan).'</p>';

        $th = 'style="background:#1d70c9;color:#fff"';
        $h .= '<table border="1"><tr>'
            .'<th '.$th.'>No.</th><th '.$th.'>Tgl Daftar</th><th '.$th.'>No NUP</th><th '.$th.'>Customer</th>'
            .'<th '.$th.'>Event</th><th '.$th.'>Ref Xendit</th><th '.$th.'>Nilai UTJ</th><th '.$th.'>Status</th>'
            .'<th '.$th.'>Tgl Bayar</th><th '.$th.'>Pencocokan</th>'
            .'</tr>';

        $totalLunas = 0.0;
        foreach ($rows as $i => $r) {
            $fake = str_starts_with((string) $r->xendit_invoice_id, 'fake_');
            $paid = $r->isUtjPaid();
            if ($paid) {
                $totalLunas += (float) $r->utj_amount;
            }
            $status = $paid ? 'LUNAS' : ($r->xendit_status ?: 'PENDING');
            $cocok = $fake ? 'SIMULASI' : ($paid ? 'KLOP' : ($r->xendit_status === 'EXPIRED' ? 'TIDAK DIBAYAR' : 'MENUNGGU'));

            $h .= '<tr>'
                .'<td>'.($i + 1).'</td>'
                .'<td>'.$e($r->created_at?->format('d/m/Y H:i')).'</td>'
                .'<td>'.$e($r->no_nup ?: '-').'</td>'
                .'<td>'.$e($r->customer?->full_name ?: $r->nama_pendaftar).'</td>'
                .'<td>'.$e($r->event?->name ?: '-').'</td>'
                .'<td>'.$e($r->xendit_external_id).'</td>'
                .'<td style="mso-number-format:\'#,##0\'">'.$n($r->utj_amount).'</td>'
                .'<td>'.$e($status).'</td>'
                .'<td>'.$e($r->xendit_paid_at?->format('d/m/Y H:i') ?: '-').'</td>'
                .'<td>'.$e($cocok).'</td>'
                .'</tr>';
        }

        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="6">TOTAL NOMINAL LUNAS</td>'
            .'<td>'.$n($totalLunas).'</td><td colspan="3">'.count($rows).' invoice</td></tr>'
            .'</table>';

        $file = 'Report-Xendit-'.(($v['from'] ?? null) ?: 'awal').'-sd-'.(($v['to'] ?? null) ?: 'sekarang').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }
}
