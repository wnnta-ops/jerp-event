<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jadwal Pembayaran — {{ $kontrak->no_kontrak }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 32px 40px; }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; }
        .brand { font-weight: 800; color: #ea7a1d; font-size: 16px; }
        .co { text-align: right; }
        .co .pt { font-weight: 800; }
        h2 { text-align: center; margin: 14px 0 4px; }
        table.meta { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.meta td { padding: 2px 4px; }
        table.meta td.k { width: 130px; color: #555; }
        table.j { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.j th, table.j td { border: 1px solid #94a3b8; padding: 6px 8px; }
        table.j th { background: #f1f5f9; text-align: left; }
        table.j td.r { text-align: right; font-variant-numeric: tabular-nums; }
        table.j tfoot td { font-weight: 800; background: #f8fafc; }
        .actions { margin-top: 18px; }
        @media print { .actions { display: none; } body { padding: 14px; } }
        .btn { display:inline-block; padding:8px 16px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">JAVA RESIDENCE</div>
        <div class="co"><div class="pt">PT. MITRA USAHA PROPERTINDO</div><div>Jadwal Pembayaran</div></div>
    </div>

    <h2>JADWAL PEMBAYARAN</h2>

    <table class="meta">
        <tr><td class="k">No. Kontrak</td><td>: <b>{{ $kontrak->no_kontrak }}</b></td><td class="k">Skema</td><td>: {{ $kontrak->skema?->code }} — {{ $kontrak->skema?->name }}</td></tr>
        <tr><td class="k">Customer</td><td>: {{ $kontrak->customer?->full_name }}</td><td class="k">Cara Bayar</td><td>: {{ $kontrak->cara_bayar }}</td></tr>
        <tr><td class="k">Unit</td><td>: {{ $kontrak->unit?->kode_unit ?: $kontrak->unit?->no_unit }}</td><td class="k">Harga</td><td>: Rp {{ number_format((float) $kontrak->harga_jual, 0, ',', '.') }}</td></tr>
    </table>

    <table class="j">
        <thead>
            <tr><th style="width:40px">No</th><th>Termin</th><th style="width:70px">Basis</th><th style="width:120px">Jatuh Tempo</th><th style="width:150px">Nominal</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['nama'] }}</td>
                    <td>{{ $r['basis'] === 'PERSEN' ? rtrim(rtrim(number_format((float) $r['persen'], 2, '.', ''), '0'), '.').'%' : 'Nominal' }}</td>
                    <td>{{ $r['jatuh'] ? $r['jatuh']->format('d M Y') : '—' }}</td>
                    <td class="r">Rp {{ number_format((float) $r['amount'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; color:#94a3b8;">Skema belum punya baris termin.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><td colspan="4">TOTAL</td><td class="r">Rp {{ number_format(array_sum(array_map(fn ($r) => (float) $r['amount'], $rows)), 0, ',', '.') }}</td></tr>
        </tfoot>
    </table>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
