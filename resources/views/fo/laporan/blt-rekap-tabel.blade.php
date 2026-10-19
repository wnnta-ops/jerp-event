{{-- Rekap Pembayaran BLT All Cluster — layar & Excel. Susunan = FORMAT BLT SUMMARY Finance. --}}
@php
    $excel = $excel ?? false;
    $jenis = \App\Modules\FO\Komisi\Services\LaporanKomisiBlt::JENIS_BLT;
    $n = fn ($x) => $excel ? round((float) $x, 2) : ((float) $x == 0 ? '' : number_format((float) $x, 0, ',', '.'));
    $tgl = fn ($d) => $d ? $d->format($excel ? 'd/m/Y' : 'd M Y') : '';
    $th = $excel ? 'style="background:#1f4e78;color:#ffffff;font-weight:bold;border:1px solid #000;text-align:center"' : 'class="px-2 py-2 text-center whitespace-nowrap border border-white/20"';
    $td = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 whitespace-nowrap"';
    $tdn = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 text-right font-mono whitespace-nowrap"';
    $lebar = count($jenis) + 2;
@endphp
<table @if (! $excel) class="w-full text-xs" @endif>
    <thead>
        @if ($excel)
            <tr><th colspan="{{ 3 + $lebar * $clusters->count() + 1 }}" style="font-weight:bold;font-size:14px">REKAP PEMBAYARAN BLT ALL CLUSTER</th></tr>
        @endif
        <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
            <th {!! $th !!} rowspan="2">BULAN</th>
            <th {!! $th !!} rowspan="2">PERIODE</th>
            <th {!! $th !!} rowspan="2">TGL PENCAIRAN</th>
            @foreach ($clusters as $c)
                <th {!! $th !!} colspan="{{ $lebar }}">{{ $c['label'] }}</th>
            @endforeach
            <th {!! $th !!} rowspan="2">GRAND TOTAL</th>
        </tr>
        <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
            @foreach ($clusters as $c)
                @foreach ($jenis as $label)
                    <th {!! $th !!}>{{ $label }}</th>
                @endforeach
                <th {!! $th !!}>PPh</th>
                <th {!! $th !!}>TOTAL</th>
            @endforeach
        </tr>
    </thead>
    <tbody @if (! $excel) class="divide-y divide-gray-100 dark:divide-white/5" @endif>
        @forelse ($rows as $r)
            <tr>
                <td {!! $td !!}>{{ $excel ? $r['bulan']->format('d/m/Y') : $r['bulan']->translatedFormat('M Y') }}</td>
                <td {!! $td !!}>{{ $r['periode'] }}</td>
                <td {!! $td !!}>{{ $tgl($r['tgl']) }}</td>
                @foreach ($clusters as $c)
                    @php($x = $r['cluster'][$c['id']])
                    @foreach (array_keys($jenis) as $j)
                        <td {!! $tdn !!}>{{ $n($x['nilai'][$j]) }}</td>
                    @endforeach
                    <td {!! $tdn !!}>{{ $n($x['pph']) }}</td>
                    <td {!! $tdn !!} @if (! $excel) style="font-weight:600" @endif>{{ $n($x['total']) }}</td>
                @endforeach
                <td {!! $tdn !!} @if (! $excel) style="font-weight:700" @endif>{{ $n($r['grand']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ 4 + $lebar * $clusters->count() }}" @if (! $excel) class="px-4 py-8 text-center text-sm text-gray-500" @endif>Belum ada BLT yang dicairkan pada periode ini.</td></tr>
        @endforelse
    </tbody>
    @if ($rows->isNotEmpty())
        <tfoot>
            <tr @if (! $excel) class="bg-gray-100 font-semibold dark:bg-white/10" @endif>
                <td colspan="3" {!! $excel ? 'style="font-weight:bold"' : 'class="px-2 py-2"' !!}>TOTAL</td>
                @foreach ($clusters as $c)
                    @php($x = $total['cluster'][$c['id']])
                    @foreach (array_keys($jenis) as $j)
                        <td {!! $tdn !!}>{{ $n($x['nilai'][$j]) }}</td>
                    @endforeach
                    <td {!! $tdn !!}>{{ $n($x['pph']) }}</td>
                    <td {!! $tdn !!}>{{ $n($x['total']) }}</td>
                @endforeach
                <td {!! $tdn !!}>{{ $n($total['grand']) }}</td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- PERBANDINGAN PENGAJUAN: total AP.INV di BO vs rekap. --}}
<table @if (! $excel) class="mt-4 text-xs" @endif>
    <tr><td></td></tr>
    <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
        <th {!! $th !!}>PERBANDINGAN PENGAJUAN</th>
        <th {!! $th !!}>TANGGAL</th>
        <th {!! $th !!}>NOMINAL</th>
    </tr>
    @forelse ($pengajuan as $p)
        <tr>
            <td {!! $td !!}>{{ $p['no'] }}</td>
            <td {!! $td !!}>{{ $tgl($p['tgl']) }}</td>
            <td {!! $tdn !!}>{{ $n($p['nominal']) }}</td>
        </tr>
    @empty
        <tr><td colspan="3" {!! $td !!}>Belum ada AP.INV dari Posting BO untuk BLT periode ini.</td></tr>
    @endforelse
    @php($totalAp = (float) $pengajuan->sum('nominal'))
    <tr>
        <td></td>
        <td {!! $excel ? 'style="font-weight:bold"' : 'class="px-2 py-1.5 font-semibold"' !!}>TOTAL</td>
        <td {!! $tdn !!}>{{ $n($totalAp) }}</td>
    </tr>
    <tr>
        <td></td>
        <td {!! $excel ? 'style="font-weight:bold"' : 'class="px-2 py-1.5 font-semibold"' !!}>SELISIH</td>
        <td {!! $tdn !!}>{{ $excel ? round($totalAp - $total['grand'], 2) : number_format($totalAp - $total['grand'], 0, ',', '.') }}</td>
    </tr>
</table>
