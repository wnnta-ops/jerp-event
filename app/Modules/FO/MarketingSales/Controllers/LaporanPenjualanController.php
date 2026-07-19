<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Laporan Penjualan (Penjualan Netto) — daftar kontrak AKTIF per periode & cluster (lokasi),
 * format meniru laporan legacy Java Core: identitas kontrak + luas + harga + Perincian DPP/PPN.
 * Preview di halaman Filament (LaporanPenjualan), export Excel lewat route fo.laporan.penjualan.excel.
 */
class LaporanPenjualanController extends Controller
{
    /**
     * Data laporan — dipakai bersama oleh preview (halaman Filament) & export Excel
     * supaya angka di layar dan di file selalu sama.
     *
     * Pemetaan kolom legacy → field: Price List = harga_jual, Diskon = diskon,
     * Diskon Tambahan = free_dp_amount (Free DP), Nilai Kontrak = total_kontrak,
     * PPN = ppn tersimpan, DPP = total_kontrak − PPN. Kolom Tambahan Harga /
     * Lain-lain / Bunga tidak punya padanan field (selalu 0).
     *
     * @return array{rows: \Illuminate\Support\Collection<int,array<string,mixed>>, total: array<string,float>}
     */
    public static function data(?int $lokasiId, ?string $from, ?string $to): array
    {
        $kontrak = Kontrak::query()
            ->with(['customer:id,full_name', 'agent:id,name', 'unit.lokasi:id,nama', 'unit.unitType:id,name', 'unit.project:id,name'])
            ->where('status', 'AKTIF')
            ->when($from, fn ($q) => $q->whereDate('tgl_kontrak', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('tgl_kontrak', '<=', $to))
            ->when($lokasiId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('lokasi_id', $lokasiId)))
            ->orderBy('tgl_kontrak')->orderBy('id')
            ->get();

        $rows = $kontrak->values()->map(function (Kontrak $k, int $i): array {
            $nilai = (float) $k->total_kontrak;
            $ppn   = (float) $k->ppn;

            return [
                'no'              => $i + 1,
                'tgl'             => $k->tgl_kontrak?->format('d-m-Y'),
                'no_kontrak'      => $k->no_kontrak,
                'customer'        => $k->customer?->full_name,
                'sales'           => $k->agent?->name,
                'project'         => $k->unit?->project?->name,
                'unit'            => $k->unit?->kode_unit,
                'tipe'            => $k->unit?->unitType?->name,
                'tipe_properti'   => $k->unit?->tipe_properti,
                'luas_tanah'      => (float) ($k->unit?->luas_tanah ?? 0),
                'luas_lebih'      => (float) ($k->unit?->luas_lebih_tanah ?? 0),
                'luas_bangun'     => (float) ($k->unit?->luas_bangunan ?? 0),
                'cara_bayar'      => $k->cara_bayar,
                'price_list'      => (float) $k->harga_jual,
                'diskon'          => (float) $k->diskon,
                'diskon_tambahan' => (float) $k->free_dp_amount,
                'tambahan_harga'  => 0.0,
                'lain'            => 0.0,
                'bunga'           => 0.0,
                'nilai_kontrak'   => $nilai,
                'dpp'             => $nilai - $ppn,
                'ppn'             => $ppn,
            ];
        });

        $total = [];
        foreach (['luas_tanah', 'luas_lebih', 'luas_bangun', 'price_list', 'diskon', 'diskon_tambahan', 'tambahan_harga', 'lain', 'bunga', 'nilai_kontrak', 'dpp', 'ppn'] as $c) {
            $total[$c] = (float) $rows->sum($c);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Export Excel (.xls tabel HTML) — meta header + tabel "C. Penjualan Netto" + baris TOTAL. */
    public function excel(Request $request): Response
    {
        $v = $request->validate([
            'lokasi_id' => ['nullable', 'integer', 'exists:fo_lokasi,id'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
        ]);
        $lokasiId = isset($v['lokasi_id']) ? (int) $v['lokasi_id'] : null;
        $d = self::data($lokasiId, $v['from'] ?? null, $v['to'] ?? null);

        $e  = fn ($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
        $n  = fn ($x) => number_format((float) $x, 2, '.', ',');
        $tglId = fn (?string $ymd) => $ymd ? Carbon::parse($ymd)->translatedFormat('d F Y') : null;

        $cluster = $lokasiId ? (Lokasi::find($lokasiId)?->nama ?? '-') : 'All Cluster';
        $periode = ($tglId($v['from'] ?? null) ?? 'Awal').' s/d '.($tglId($v['to'] ?? null) ?? 'Sekarang');
        $now = now()->locale('id');
        $dihasilkan = 'Laporan dihasilkan pada hari '.$now->translatedFormat('l, d F Y H:i:s')
            .' dari workstation '.$request->ip().' oleh user '.($request->user()?->name ?? '-');

        $h = '<h3>LAPORAN PENJUALAN</h3>'
            .'<p>Periode : '.$e($periode).'<br>'
            .'Cluster : '.$e($cluster).'<br>'
            .$e($dihasilkan).'</p>'
            .'<h4>C. Penjualan Netto</h4>';

        $th = 'style="background:#1d70c9;color:#fff"';
        $h .= '<table border="1">'
            .'<tr>'
            .'<th rowspan="2" '.$th.'>No.</th><th rowspan="2" '.$th.'>Tgl. Kontrak</th><th rowspan="2" '.$th.'>No. Kontrak</th>'
            .'<th rowspan="2" '.$th.'>Customer</th><th rowspan="2" '.$th.'>Sales</th><th rowspan="2" '.$th.'>Project</th>'
            .'<th rowspan="2" '.$th.'>Unit</th><th rowspan="2" '.$th.'>Tipe</th><th rowspan="2" '.$th.'>Tipe Properti</th>'
            .'<th rowspan="2" '.$th.'>Luas Tanah</th><th rowspan="2" '.$th.'>Luas Lebih</th><th rowspan="2" '.$th.'>Luas Bangunan</th>'
            .'<th rowspan="2" '.$th.'>Cara Bayar</th><th rowspan="2" '.$th.'>Price List</th><th rowspan="2" '.$th.'>Diskon</th>'
            .'<th rowspan="2" '.$th.'>Diskon Tambahan</th><th rowspan="2" '.$th.'>Tambahan Harga</th><th rowspan="2" '.$th.'>Lain-lain</th>'
            .'<th rowspan="2" '.$th.'>Bunga</th><th rowspan="2" '.$th.'>Nilai Kontrak</th>'
            .'<th colspan="2" '.$th.'>Perincian Kontrak</th>'
            .'</tr>'
            .'<tr><th '.$th.'>DPP</th><th '.$th.'>PPN</th></tr>';

        foreach ($d['rows'] as $r) {
            $h .= '<tr>'
                .'<td>'.$r['no'].'</td>'
                .'<td>'.$e($r['tgl']).'</td>'
                .'<td>'.$e($r['no_kontrak']).'</td>'
                .'<td>'.$e($r['customer']).'</td>'
                .'<td>'.$e($r['sales']).'</td>'
                .'<td>'.$e($r['project']).'</td>'
                .'<td>'.$e($r['unit']).'</td>'
                .'<td>'.$e($r['tipe']).'</td>'
                .'<td>'.$e($r['tipe_properti']).'</td>'
                .'<td>'.$n($r['luas_tanah']).'</td>'
                .'<td>'.$n($r['luas_lebih']).'</td>'
                .'<td>'.$n($r['luas_bangun']).'</td>'
                .'<td>'.$e($r['cara_bayar']).'</td>'
                .'<td>'.$n($r['price_list']).'</td>'
                .'<td>'.$n($r['diskon']).'</td>'
                .'<td>'.$n($r['diskon_tambahan']).'</td>'
                .'<td>'.$n($r['tambahan_harga']).'</td>'
                .'<td>'.$n($r['lain']).'</td>'
                .'<td>'.$n($r['bunga']).'</td>'
                .'<td>'.$n($r['nilai_kontrak']).'</td>'
                .'<td>'.$n($r['dpp']).'</td>'
                .'<td>'.$n($r['ppn']).'</td>'
                .'</tr>';
        }

        $t = $d['total'];
        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="9">TOTAL</td>'
            .'<td>'.$n($t['luas_tanah']).'</td><td>'.$n($t['luas_lebih']).'</td><td>'.$n($t['luas_bangun']).'</td><td></td>'
            .'<td>'.$n($t['price_list']).'</td><td>'.$n($t['diskon']).'</td><td>'.$n($t['diskon_tambahan']).'</td>'
            .'<td>'.$n($t['tambahan_harga']).'</td><td>'.$n($t['lain']).'</td><td>'.$n($t['bunga']).'</td>'
            .'<td>'.$n($t['nilai_kontrak']).'</td><td>'.$n($t['dpp']).'</td><td>'.$n($t['ppn']).'</td></tr>'
            .'</table>';

        $file = 'Laporan-Penjualan-'.(($v['from'] ?? null) ?: 'awal').'-sd-'.(($v['to'] ?? null) ?: 'sekarang').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }
}
