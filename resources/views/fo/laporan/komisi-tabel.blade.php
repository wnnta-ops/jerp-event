{{-- Laporan Komisi — dipakai layar ($excel=false) & export Excel ($excel=true). Susunan = FORMAT KOMISI Finance. --}}
@php
    $excel = $excel ?? false;
    $n = fn ($x) => $x === null ? '' : ($excel ? round((float) $x, 2) : number_format((float) $x, 0, ',', '.'));
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format($excel ? 'd/m/Y' : 'd M Y') : '';
    $th = $excel ? 'style="background:#1f4e78;color:#ffffff;font-weight:bold;border:1px solid #000"' : 'class="px-2 py-2 text-left whitespace-nowrap"';
    $td = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 whitespace-nowrap"';
    $tdn = $excel ? 'style="border:1px solid #999"' : 'class="px-2 py-1.5 text-right font-mono whitespace-nowrap"';
@endphp
<table @if (! $excel) class="w-full text-xs" @endif>
    <thead>
        <tr @if (! $excel) class="bg-primary-600 text-white" @endif>
            @foreach (['No.', 'Tanggal Pengajuan', 'Periode Komisi', 'Sales', 'Customer', 'No. Kontrak', 'No. Unit', 'TGL SPR', 'TGL AKAD/LUNAS', 'CARA BAYAR', 'CLUSTER', 'TAHAP', 'HARGA NETT', 'DPP', 'BPHTB', 'PPh', 'Komisi', 'Nilai', 'Nilai PPH', 'Nilai PPN', 'Nilai Bayar', 'Nomor Rekening', 'Atas Nama', 'Bank'] as $h)
                <th {!! $th !!}>{{ $h }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody @if (! $excel) class="divide-y divide-gray-100 dark:divide-white/5" @endif>
        @forelse ($rows as $r)
            <tr>
                <td {!! $td !!}>{{ $r['no'] }}</td>
                <td {!! $td !!}>{{ $tgl($r['tgl_pengajuan']) }}</td>
                <td {!! $td !!}>{{ $tgl($r['periode']) }}</td>
                <td {!! $td !!}>{{ $r['sales'] }}</td>
                <td {!! $td !!}>{{ $r['customer'] }}</td>
                <td {!! $td !!}>{{ $r['no_kontrak'] }}</td>
                <td {!! $td !!}>{{ $r['unit'] }}</td>
                <td {!! $td !!}>{{ $tgl($r['tgl_spr']) }}</td>
                <td {!! $td !!}>{{ $tgl($r['tgl_akad']) }}</td>
                <td {!! $td !!}>{{ $r['cara_bayar'] }}</td>
                <td {!! $td !!}>{{ $r['cluster'] }}</td>
                <td {!! $td !!}>{{ $r['tahap'] }}</td>
                <td {!! $tdn !!}>{{ $n($r['harga_nett']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['dpp']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['bphtb']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['pph_dasar']) }}</td>
                <td {!! $tdn !!}>{{ $r['persen'] === null ? '' : ($excel ? $r['persen'] : rtrim(rtrim(number_format($r['persen'] * 100, 2, ',', '.'), '0'), ',').'%') }}</td>
                <td {!! $tdn !!}>{{ $n($r['nilai']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['pph']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['ppn']) }}</td>
                <td {!! $tdn !!}>{{ $n($r['bayar']) }}</td>
                <td {!! $td !!}>{{ $r['rek_number'] }}</td>
                <td {!! $td !!}>{{ $r['rek_holder'] }}</td>
                <td {!! $td !!}>{{ $r['rek_bank'] }}</td>
            </tr>
        @empty
            <tr><td colspan="24" @if (! $excel) class="px-4 py-8 text-center text-sm text-gray-500" @endif>Tidak ada komisi pada filter ini. Coba ubah "Berdasarkan" (tanggal pengajuan / cair) atau perlebar periodenya.</td></tr>
        @endforelse
    </tbody>
    @if ($rows->isNotEmpty())
        <tfoot>
            <tr @if (! $excel) class="bg-gray-100 font-semibold dark:bg-white/10" @endif>
                <td colspan="16"></td>
                <td {!! $excel ? 'style="font-weight:bold"' : 'class="px-2 py-2"' !!}>TOTAL</td>
                <td {!! $tdn !!}>{{ $n($total['nilai']) }}</td>
                <td {!! $tdn !!}>{{ $n($total['pph']) }}</td>
                <td {!! $tdn !!}>{{ $n($total['ppn']) }}</td>
                <td {!! $tdn !!}>{{ $n($total['bayar']) }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    @endif
</table>
