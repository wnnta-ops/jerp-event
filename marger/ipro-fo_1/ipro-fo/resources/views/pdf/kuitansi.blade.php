@extends('pdf.layout', ['title' => 'Kuitansi '.$kuitansi->kuitansi_number])
@section('content')

<h1>KUITANSI</h1>
<div class="doc-info">
    <div class="row"><div class="label">Nomor:</div><div class="value mono">{{ $kuitansi->kuitansi_number }}</div></div>
    <div class="row"><div class="label">Tanggal:</div><div class="value">{{ format_tanggal($kuitansi->issued_date) }}</div></div>
    <div class="row"><div class="label">Diterima dari:</div><div class="value">{{ $kuitansi->customer?->full_name }}</div></div>
    <div class="row"><div class="label">Jumlah:</div><div class="value mono"><strong>{{ format_rupiah($kuitansi->amount) }}</strong></div></div>
    <div class="row"><div class="label">Terbilang:</div><div class="value" style="font-style: italic">{{ $kuitansi->terbilang }}</div></div>
</div>

<h2>Untuk Pembayaran</h2>
<table>
    <thead><tr><th>Deskripsi</th><th class="right">Nominal</th></tr></thead>
    <tbody>
        @foreach ($kuitansi->items as $item)
        <tr><td>{{ $item->description }}</td><td class="right mono">{{ format_rupiah($item->amount) }}</td></tr>
        @endforeach
        <tr class="total-row"><td>TOTAL</td><td class="right mono">{{ format_rupiah($kuitansi->amount) }}</td></tr>
    </tbody>
</table>

<div class="signature" style="text-align: right">
    <p>Hormat kami,</p>
    <div class="name">{{ $kuitansi->signed_by ?? 'PT MUP' }}</div>
</div>

@endsection
