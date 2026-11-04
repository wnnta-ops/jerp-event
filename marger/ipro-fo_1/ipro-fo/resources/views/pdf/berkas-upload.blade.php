{{-- Cetak Upload Berkas — HTML siap-cetak (dialog cetak → Simpan sebagai PDF). Dipanggil ApBerkasController::print. --}}
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp '.number_format((float) $v, 0, ',', '.');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $b->berkas_number }} — Upload Berkas</title>
<style>
    @page { size: A4; margin: 14mm 14mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; margin: 0; }
    .kop { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #111; padding-bottom: 6px; }
    .kop h1 { font-size: 16px; margin: 0; letter-spacing: .02em; }
    .kop .co { font-size: 11px; color: #444; }
    .no { font-family: 'Courier New', monospace; font-size: 13px; font-weight: bold; text-align: right; }
    .meta { margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 2px 18px; }
    .meta div { display: flex; gap: 6px; } .meta b { width: 120px; flex: none; font-weight: normal; color: #555; }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; margin: 14px 0 4px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    td, th { border: 1px solid #bbb; padding: 4px 6px; vertical-align: top; }
    th { background: #f2f2f2; text-align: left; }
    .n { text-align: right; white-space: nowrap; }
    .tot td { font-weight: bold; }
    .ttd { margin-top: 18px; table-layout: fixed; }
    .ttd td { text-align: center; font-size: 10px; vertical-align: top; padding: 4px; height: 96px; }
    .ttd .j { font-weight: bold; border-bottom: 1px solid #bbb; height: auto; }
    .ttd img { max-height: 46px; max-width: 100%; display: block; margin: 4px auto 2px; }
    .ttd .kosong { height: 52px; }
    .ttd .nm { font-weight: bold; } .ttd .tg { color: #666; }
    .foot { margin-top: 12px; font-size: 9px; color: #777; display: flex; justify-content: space-between; }
    .cap { display: inline-block; border: 1px solid #111; padding: 1px 6px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    @media screen { body { max-width: 210mm; margin: 12px auto; padding: 0 12px; } }
</style>
</head>
<body onload="window.print()">
    <div class="kop">
        <div>
            <div class="co">PT. MUP · Java Residence · Java Core System</div>
            <h1>BERKAS TAGIHAN / PERMOHONAN PEMBAYARAN</h1>
        </div>
        <div class="no">{{ $b->berkas_number }}<br><span class="cap">{{ $statusLabel }}{{ $b->trashed() ? ' · dihapus' : '' }}</span></div>
    </div>

    <div class="meta">
        <div><b>Tanggal unggah</b>{{ $b->upload_date?->format('d/m/Y') }}</div>
        <div><b>Tanggal tagihan</b>{{ $b->document_date?->format('d/m/Y') ?? '—' }}</div>
        <div><b>Jatuh tempo</b>{{ $b->needed_date?->format('d/m/Y') ?? '—' }}</div>
        <div><b>Supplier</b>{{ $b->supplier?->name ?? '—' }}</div>
        <div><b>No. tagihan supplier</b>{{ $b->supplier_invoice_no ?? '—' }}</div>
        <div><b>Dept Head</b>{{ $b->deptHead?->name ?? '—' }}</div>
        <div style="grid-column:1 / 3"><b>Keterangan</b>{{ $b->description }}</div>
    </div>

    <h2>Nilai tagihan</h2>
    <table>
        <tr class="tot"><td>TOTAL</td><td class="n">{{ $rp($b->amount) }}</td></tr>
    </table>

    <h2>Dokumen pendukung ({{ $b->files->count() }})</h2>
    <table>
        <tr><th style="width:28px">No</th><th>Jenis</th><th>Nama file</th></tr>
        @foreach ($b->files as $i => $f)
            <tr><td>{{ $i + 1 }}</td><td>{{ $f->labelJenis() ?? 'Jenis lain' }}</td><td>{{ $f->original_name }}</td></tr>
        @endforeach
    </table>

    @if ($invoices->isNotEmpty())
        <h2>Invoice hutang dari berkas ini</h2>
        <table>
            <tr><th>No. invoice</th><th>Tanggal</th><th class="n">Total</th></tr>
            @foreach ($invoices as $inv)
                <tr><td>{{ $inv->invoice_number }}</td><td>{{ $inv->invoice_date?->format('d/m/Y') }}</td><td class="n">{{ $rp($inv->total) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($b->cancel_reason || $b->close_reason)
        <h2>Catatan</h2>
        <p style="margin:0">{{ $b->cancel_reason ? 'Dibatalkan: '.$b->cancel_reason : 'Ditutup: '.$b->close_reason }}</p>
    @endif

    <table class="ttd">
        <tr>@foreach ($ttd as $k)<td class="j">{{ $k['title'] }}</td>@endforeach</tr>
        <tr>
            @foreach ($ttd as $k)
                <td>
                    @if ($k['img'])<img src="{{ $k['img'] }}" alt="TTD {{ $k['name'] }}">@else<div class="kosong"></div>@endif
                    <div class="nm">{{ $k['name'] ?? '(…………………)' }}</div>
                    <div class="tg">{{ $k['signed'] ? $k['at'] : 'Belum disetujui' }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="foot">
        <span>Dicetak {{ now()->format('d/m/Y H:i') }} oleh {{ $dicetakOleh }} · Java Core System</span>
        <span>TTD distempel otomatis dari persetujuan di aplikasi</span>
    </div>
</body>
</html>
