{{-- Detail BLT satu cluster — layar & Excel. Susunan = FORMAT BLT DETAIL-Cx Finance. --}}
@php
    $excel = $excel ?? false;
    $jenis = \App\Modules\FO\Komisi\Services\LaporanKomisiBlt::JENIS_BLT;
    $n = fn ($x) => $excel ? round((float) $x, 2) : number_format((float) $x, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format($excel ? 'd/m/Y' : 'd M Y') : '';
    $th = $excel ? 'style="background:#1f4e78;color:#ffffff;font-weight:bold;border:1px solid #000;text-align:center"' : 'class="px-2 py-2 text-center whitespace-nowrap border border-white/20"';
    $td = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 whitespace-nowrap"';
    $tdn = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 text-right font-mono whitespace-nowrap"';
@endphp
<table @if (! $excel) class="w-full text-xs" @endif>
    <thead>
        @if ($excel)
            <tr><th colspan="{{ 6 + count($jenis) * 4 + 2 }}" style="font-weight:bold;font-size:14px">MASTER BLT {{ $judul }}</th></tr>
        @endif
        <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
            <th {!! $th !!} rowspan="2">NO</th>
            <th {!! $th !!} rowspan="2">UNIT</th>
            <th {!! $th !!} rowspan="2">PROJECT</th>
            <th {!! $th !!} rowspan="2">NAMA PEMBELI</th>
            <th {!! $th !!} rowspan="2">NOTES</th>
            <th {!! $th !!} rowspan="2">CARA BAYAR</th>
            @foreach ($jenis as $label)
                <th {!! $th !!} colspan="4">{{ $label }}</th>
            @endforeach
            <th {!! $th !!} rowspan="2">TOTAL</th>
            <th {!! $th !!} rowspan="2">PPh</th>
        </tr>
        <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
            @foreach ($jenis as $label)
                <th {!! $th !!}>TGL</th>
                <th {!! $th !!}>NILAI</th>
                <th {!! $th !!}>PPh</th>
                <th {!! $th !!}>GL</th>
            @endforeach
        </tr>
    </thead>
    <tbody @if (! $excel) class="divide-y divide-gray-100 dark:divide-white/5" @endif>
        @forelse ($rows as $r)
            <tr>
                <td {!! $td !!}>{{ $r['no'] }}</td>
                <td {!! $td !!}>{{ $r['unit'] }}</td>
                <td {!! $td !!}>{{ $r['project'] }}</td>
                <td {!! $td !!}>{{ $r['pembeli'] }}</td>
                <td {!! $td !!}>{{ $r['notes'] }}</td>
                <td {!! $td !!}>{{ $r['cara_bayar'] }}</td>
                @foreach (array_keys($jenis) as $j)
                    @php($x = $r['jenis'][$j])
                    <td {!! $td !!}>{{ $x ? $tgl($x['tgl']) : '' }}</td>
                    <td {!! $tdn !!}>{{ $x ? $n($x['nilai']) : '' }}</td>
                    <td {!! $tdn !!}>{{ $x && $x['pph'] > 0 ? $n($x['pph']) : '' }}</td>
                    <td {!! $td !!}>{{ $x['gl'] ?? '' }}</td>
                @endforeach
                <td {!! $tdn !!} @if (! $excel) style="font-weight:600" @endif>{{ $n($r['total']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['pph']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ 8 + count($jenis) * 4 }}" @if (! $excel) class="px-4 py-8 text-center text-sm text-gray-500" @endif>Belum ada BLT yang dicairkan di cluster ini pada periode tersebut.</td></tr>
        @endforelse
    </tbody>
    @if ($rows->isNotEmpty())
        <tfoot>
            <tr @if (! $excel) class="bg-gray-100 font-semibold dark:bg-white/10" @endif>
                <td colspan="6" {!! $excel ? 'style="font-weight:bold"' : 'class="px-2 py-2"' !!}>TOTAL</td>
                @foreach (array_keys($jenis) as $j)
                    <td></td>
                    <td {!! $tdn !!}>{{ $n($total['jenis'][$j]['nilai']) }}</td>
                    <td {!! $tdn !!}>{{ $n($total['jenis'][$j]['pph']) }}</td>
                    <td></td>
                @endforeach
                <td {!! $tdn !!}>{{ $n($total['total']) }}</td>
                <td {!! $tdn !!}>{{ $n($total['pph']) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
