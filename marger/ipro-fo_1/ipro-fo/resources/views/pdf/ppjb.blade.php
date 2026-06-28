@extends('pdf.layout', ['title' => 'PPJB '.$ppjb->ppjb_number])
@section('content')

<h1 style="text-align:center">PERJANJIAN PENGIKATAN JUAL BELI</h1>
<p style="text-align:center" class="mono">No: {{ $ppjb->ppjb_number }}</p>

<div class="doc-info">
    <div class="row"><div class="label">Tanggal:</div><div class="value">{{ format_tanggal($ppjb->signed_at) }}</div></div>
    <div class="row"><div class="label">Kontrak Ref:</div><div class="value mono">{{ $ppjb->contract?->contract_number }}</div></div>
</div>

@if ($ppjb->contract?->snapshot)
<h2>Detail Transaksi</h2>
<div class="doc-info">
    <div class="row"><div class="label">Unit:</div><div class="value">{{ $ppjb->contract->snapshot['unit_code'] ?? '-' }}</div></div>
    <div class="row"><div class="label">Total Harga:</div><div class="value mono"><strong>{{ format_rupiah($ppjb->contract->snapshot['total_price'] ?? 0) }}</strong></div></div>
    <div class="row"><div class="label">PPN:</div><div class="value mono">{{ format_rupiah($ppjb->contract->snapshot['ppn'] ?? 0) }}</div></div>
    <div class="row"><div class="label">Diskon:</div><div class="value mono">{{ format_rupiah($ppjb->contract->snapshot['discount'] ?? 0) }}</div></div>
</div>
@endif

@if ($ppjb->contract?->clauses?->count())
<h2>Pasal-Pasal Perjanjian</h2>
@foreach ($ppjb->contract->clauses as $c)
<div style="margin-bottom: 10px">
    <p><strong>Pasal {{ $c->clause_no }}: {{ $c->title }}</strong></p>
    <p>{{ $c->body }}</p>
</div>
@endforeach
@endif

<div class="signature" style="display: flex; justify-content: space-between; margin-top: 60px">
    <div>
        <p>Pihak Pertama (Developer)</p>
        <div class="name">PT. MUP</div>
    </div>
    <div>
        <p>Pihak Kedua (Pembeli)</p>
        <div class="name">&nbsp;</div>
    </div>
</div>

@endsection
