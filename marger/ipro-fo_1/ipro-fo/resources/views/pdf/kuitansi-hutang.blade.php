{{-- Cetak Kuitansi Hutang — HTML siap-cetak (dialog cetak → Simpan sebagai PDF). Dipanggil ApKuitansiController::print.
     Kuitansi Memo Urgent: no. memo + TTD Kasir / Direktur / MW / BA (digital, distempel otomatis setelah tahapnya disetujui). --}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $memoNo = $k->memo?->memo_number ?? $k->lines->map(fn ($l) => $l->invoice?->memo?->memo_number)->filter()->unique()->implode(', ');
    $um = (float) $k->lines->sum('um_amount');
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $k->kuitansi_number }} — Kuitansi Hutang</title>
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
    .memo { margin-top: 10px; border: 1px solid #c00; color: #900; padding: 6px 8px; }
    .ttd { margin-top: 18px; table-layout: fixed; }
    .ttd td { text-align: center; font-size: 10px; vertical-align: top; padding: 4px; height: 96px; }
    .ttd .j { font-weight: bold; border-bottom: 1px solid #bbb; height: auto; }
    .ttd img { max-height: 46px; max-width: 100%; display: block; margin: 4px auto 2px; }
    .ttd .kosong { height: 52px; }
    .ttd .nm { font-weight: bold; } .ttd .tg { color: #666; }
    .foot { margin-top: 14px; display: flex; justify-content: space-between; font-size: 9px; color: #777; }
    @media screen { body { max-width: 210mm; margin: 12px auto; padding: 0 12px; } }
</style>
</head>
<body onload="window.print()">
    <div class="kop">
        <div><h1>KUITANSI HUTANG</h1><div class="co">Pengeluaran Kas &amp; Bank (AP)</div></div>
        <div class="no">{{ $k->kuitansi_number }}@if ($k->status !== 'active')<br><span style="color:#c00">{{ strtoupper($k->status) }}</span>@endif</div>
    </div>

    <div class="meta">
        <div><b>Tanggal</b> {{ $k->kuitansi_date?->format('d/m/Y') }}</div>
        <div><b>Supplier</b> {{ trim(($k->supplier?->code ?? '').' '.($k->supplier?->name ?? '')) }}</div>
        <div><b>Dari Kas/Bank</b> {{ $k->cashAccount?->code }} {{ $k->cashAccount?->name }}</div>
        <div><b>Alat Transaksi</b> {{ $alat[$k->alat_transaksi] ?? $k->alat_transaksi }}{{ $k->bg_number ? ' · '.$k->bg_number : '' }}</div>
        <div><b>No. Memo Urgent</b> {{ $memoNo ?: '—' }}</div>
        <div><b>No. Jurnal</b> {{ $k->journal?->journal_number ?? '—' }}</div>
        <div style="grid-column: span 2"><b>Keterangan</b> {{ $k->description }}</div>
    </div>

    @if ($k->memo)
        <h2>Pembayaran Memo Urgent</h2>
        <table>
            <tr><th>No. Memo</th><th>Perihal</th><th>Akun Uang Muka</th><th class="n">Nominal Memo</th><th class="n">Dibayar</th></tr>
            <tr>
                <td>{{ $k->memo->memo_number }}</td><td>{{ $k->memo->subject }}</td>
                <td>{{ $k->advanceAccount?->code }} {{ $k->advanceAccount?->name }}</td>
                <td class="n">{{ $rp($k->memo->amount) }}</td><td class="n">{{ $rp($k->total) }}</td>
            </tr>
        </table>
        <div class="memo">Dibayar sebelum invoice hutang — invoice hutang supplier ini WAJIB menyusul dan menarik memo {{ $k->memo->memo_number }}.</div>
    @else
        <h2>Invoice yang Dibayar</h2>
        <table>
            <tr><th>No. Invoice</th><th>Keterangan</th><th class="n">Pelunasan</th><th class="n">Potong Uang Muka Memo</th><th class="n">Dibayar Kas/Bank</th></tr>
            @foreach ($k->lines as $l)
                <tr>
                    <td>{{ $l->invoice?->invoice_number }}</td><td>{{ $l->invoice?->description }}</td>
                    <td class="n">{{ $rp($l->amount) }}</td>
                    <td class="n">{{ (float) $l->um_amount > 0 ? '('.$rp($l->um_amount).')' : '—' }}</td>
                    <td class="n">{{ $rp((float) $l->amount - (float) $l->um_amount) }}</td>
                </tr>
            @endforeach
            <tr class="tot"><td colspan="2" class="n">Total</td><td class="n">{{ $rp($k->lines->sum('amount')) }}</td><td class="n">{{ $um > 0 ? '('.$rp($um).')' : '—' }}</td><td class="n">{{ $rp($k->total) }}</td></tr>
        </table>
    @endif

    <table style="margin-top:10px"><tr class="tot"><td>Total Dibayar (Kas/Bank)</td><td class="n" style="font-size:13px">{{ $rp($k->total) }}</td></tr></table>

    @if ($ttd)
        <table class="ttd">
            <tr>@foreach ($ttd as $t)<td class="j">{{ $t['title'] }}</td>@endforeach</tr>
            <tr>
                @foreach ($ttd as $t)
                    <td>
                        @if ($t['img'])<img src="{{ $t['img'] }}" alt="TTD {{ $t['name'] }}">@else<div class="kosong"></div>@endif
                        <div class="nm">{{ $t['name'] ?? '(…………………)' }}</div>
                        <div class="tg">{{ $t['signed'] ? $t['at'] : 'Menunggu persetujuan' }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <div class="foot">
        <span>Dicetak {{ now()->format('d/m/Y H:i') }} oleh {{ $dicetakOleh }} · Java Core System</span>
        @if ($ttd)<span>TTD distempel otomatis dari persetujuan memo di aplikasi</span>@endif
    </div>
</body>
</html>
