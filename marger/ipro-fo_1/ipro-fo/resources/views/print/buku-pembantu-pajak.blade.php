<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Buku Pembantu Pajak — {{ $month }}/{{ $year }}</title>
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #c2410c; padding-bottom: 10px; margin-bottom: 16px; }
        .brand { font-size: 13pt; font-weight: 800; }
        .brand small { display: block; font-size: 9pt; color: #666; font-weight: 400; }
        .doc-title { text-align: right; } .doc-title h1 { font-size: 13pt; margin: 0; color: #9a3412; }
        .card { margin-bottom: 18px; page-break-inside: avoid; }
        .card h2 { font-size: 11pt; margin: 0 0 4px; padding: 4px 8px; background: #fff3e0; color: #9a3412; border-left: 4px solid #c2410c; display: flex; justify-content: space-between; }
        .card h2 .code { font-family: 'Courier New', monospace; font-size: 9.5pt; color: #777; }
        table.led { width: 100%; border-collapse: collapse; }
        table.led th { background: #f3f4f6; text-align: left; padding: 4px 6px; font-size: 8.5pt; text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
        table.led td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; font-size: 9pt; }
        table.led td.r { text-align: right; font-family: 'Courier New', monospace; }
        table.led tr.open td, table.led tr.close td { font-weight: 700; background: #fafafa; }
        table.led tr.close td { border-top: 2px solid #c2410c; }
        .empty { padding: 6px; color: #999; font-style: italic; font-size: 9pt; }
        .footer { margin-top: 20px; text-align: center; font-size: 8.5pt; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
        .print-bar { position: fixed; top: 0; left: 0; right: 0; background: #c2410c; color: #fff; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; z-index: 999; }
        .print-bar button { background: #fff; color: #c2410c; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 11pt; }
        @media print { .print-bar { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
@php
    $fmt = fn ($v) => ((float) $v == 0.0) ? '—' : number_format((float) $v, 0, ',', '.');
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month] ?? $month;
@endphp
    <div class="print-bar"><span>📄 Buku Pembantu Pajak siap dicetak — Ctrl+P (Save as PDF)</span><button onclick="window.print()">🖨 Cetak / Simpan PDF</button></div>
    <div style="height: 44px;"></div>

    <div class="header">
        <div class="brand">{{ $company['name'] }}<small>NPWP: {{ $company['npwp'] }}</small></div>
        <div class="doc-title"><h1>BUKU PEMBANTU PAJAK</h1><div>Masa {{ $bulan }} {{ $year }}</div></div>
    </div>

    @foreach($cards as $c)
        <div class="card">
            <h2><span>{{ $c['label'] }}</span><span class="code">{{ $c['code'] }}</span></h2>
            <table class="led">
                <thead><tr><th style="width:13%">Tanggal</th><th style="width:16%">No. Jurnal</th><th>Keterangan</th><th style="width:14%;text-align:right">Debit</th><th style="width:14%;text-align:right">Kredit</th><th style="width:15%;text-align:right">Saldo</th></tr></thead>
                <tbody>
                    <tr class="open"><td colspan="5">Saldo Awal Masa</td><td class="r">{{ $fmt($c['opening']) }}</td></tr>
                    @forelse($c['rows'] as $r)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</td>
                            <td style="font-family:monospace;font-size:8pt">{{ $r['no'] }}</td>
                            <td>{{ $r['desc'] ?? $r['ref'] }}</td>
                            <td class="r">{{ $fmt($r['debit']) }}</td>
                            <td class="r">{{ $fmt($r['credit']) }}</td>
                            <td class="r">{{ $fmt($r['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">Tidak ada mutasi pada masa ini.</td></tr>
                    @endforelse
                    <tr class="close"><td colspan="3">Total / Saldo Akhir</td><td class="r">{{ $fmt($c['total_debit']) }}</td><td class="r">{{ $fmt($c['total_credit']) }}</td><td class="r">{{ $fmt($c['closing']) }}</td></tr>
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="footer">Dicetak dari Java Core System · {{ now()->format('d M Y H:i') }} · Saldo akun pajak normal kredit (PPN Keluaran/PPh) atau debit (PPN Masukan).</div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
