<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SPT Masa PPh — {{ $masaRoman }}/{{ $year }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10.5pt; color: #1a1a1a; line-height: 1.45; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #7c3aed; padding-bottom: 12px; margin-bottom: 16px; }
        .brand { font-size: 13pt; font-weight: 800; } .brand small { display: block; font-size: 9pt; color: #666; font-weight: 400; }
        .doc-title { text-align: right; } .doc-title h1 { font-size: 14pt; margin: 0; color: #6d28d9; } .doc-title .num { font-family: 'Courier New', monospace; font-size: 11pt; }
        h2 { font-size: 11pt; margin: 16px 0 6px; padding: 4px 8px; background: #f5f3ff; color: #6d28d9; border-left: 4px solid #7c3aed; }
        table { width: 100%; border-collapse: collapse; }
        .sum td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 10.5pt; } .sum td.r { text-align: right; font-family: 'Courier New', monospace; }
        .sum tr.total td { border-top: 2px solid #7c3aed; font-weight: 800; font-size: 11.5pt; padding-top: 7px; }
        table.det th { background: #f3f4f6; text-align: left; padding: 4px 6px; font-size: 8.5pt; text-transform: uppercase; color: #555; }
        table.det td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; font-size: 9pt; } table.det td.r { text-align: right; font-family: 'Courier New', monospace; }
        .result { margin-top: 12px; padding: 12px 14px; border-radius: 8px; background: #f5f3ff; color: #6d28d9; display: flex; justify-content: space-between; align-items: center; }
        .result .amt { font-family: 'Courier New', monospace; font-size: 15pt; font-weight: 800; }
        .note { margin-top: 10px; font-size: 9pt; color: #555; background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 8px 10px; }
        .signatures { display: flex; justify-content: flex-end; margin-top: 36px; } .sign-box { text-align: center; width: 45%; }
        .sign-line { margin-top: 56px; border-top: 1px solid #333; padding-top: 4px; font-size: 10pt; }
        .footer { margin-top: 22px; text-align: center; font-size: 8.5pt; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
        .print-bar { position: fixed; top: 0; left: 0; right: 0; background: #7c3aed; color: #fff; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; z-index: 999; }
        .print-bar button { background: #fff; color: #6d28d9; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 11pt; }
        @media print { .print-bar { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
@php
    $fmt = fn ($v) => number_format((float) ($v ?? 0), 0, ',', '.');
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month] ?? $month;
@endphp
    <div class="print-bar"><span>📄 SPT Masa PPh siap dicetak — Ctrl+P (Save as PDF)</span><button onclick="window.print()">🖨 Cetak / Simpan PDF</button></div>
    <div style="height: 44px;"></div>

    <div class="header">
        <div class="brand">{{ $company['name'] }}<small>NPWP: {{ $company['npwp'] }} · {{ $company['address'] }}</small></div>
        <div class="doc-title"><h1>SPT MASA PPh</h1><div class="num">Pemotongan/Pemungutan — Masa {{ $masaRoman }} ({{ $bulan }}) {{ $year }}</div></div>
    </div>

    <h2>Rekapitulasi PPh Dipotong/Dipungut</h2>
    <table class="sum">
        @foreach($byArticle as $label => $amt)
            <tr><td>{{ $label }}</td><td class="r">{{ $fmt($amt) }}</td></tr>
        @endforeach
        <tr class="total"><td>TOTAL PPh TERUTANG (disetor)</td><td class="r">{{ $fmt($totalTerutang) }}</td></tr>
    </table>

    <div class="result"><span>TOTAL PPh YANG WAJIB DISETOR</span><span class="amt">Rp {{ $fmt($totalTerutang) }}</span></div>

    @if($prepaid > 0)
        <div class="note"><b>PPh Dibayar Dimuka (kredit pajak):</b> Rp {{ $fmt($prepaid) }} — PPh yang dipungut pelanggan atas piutang (AR). Bukan kewajiban setor; mengurangi PPh Badan tahunan.</div>
    @endif

    <h2>Daftar Bukti Potong / Pemungutan</h2>
    <table class="det">
        <thead><tr><th>Sumber</th><th>Tanggal</th><th>Lawan Transaksi</th><th>No. Dokumen</th><th>Pasal</th><th style="text-align:right">DPP/Bruto</th><th style="text-align:right">PPh</th></tr></thead>
        <tbody>
            @forelse($rows as $r)
                <tr>
                    <td>{{ $r['source'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</td>
                    <td>{{ $r['partner'] }}</td>
                    <td style="font-family:monospace;font-size:8pt">{{ $r['doc_no'] }}</td>
                    <td>{{ $r['article'] }}</td>
                    <td class="r">{{ $fmt($r['base']) }}</td>
                    <td class="r">{{ $fmt($r['pph']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:8px;color:#999;font-style:italic">Tidak ada pemotongan PPh pada masa ini.</td></tr>
            @endforelse
        </tbody>
        @if(count($rows))
            <tfoot><tr style="font-weight:700;border-top:2px solid #7c3aed"><td colspan="6" style="padding:5px 6px;text-align:right">Total</td><td class="r" style="padding:5px 6px">{{ $fmt($totalTerutang) }}</td></tr></tfoot>
        @endif
    </table>

    <div class="signatures">
        <div class="sign-box"><div>{{ $company['address'] }}, {{ now()->format('d M Y') }}</div><div>Pemotong/Pemungut Pajak</div><div class="sign-line">{{ $company['name'] }}</div></div>
    </div>
    <div class="footer">Dicetak dari Java Core System · {{ now()->format('d M Y H:i') }} · PPh 21 dari Payroll, PPh 23/4(2) dari pembayaran ke supplier (AP).</div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
