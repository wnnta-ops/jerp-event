<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SPT Masa PPN — {{ $masaRoman }}/{{ $year }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.5; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #c2410c; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 13pt; font-weight: 800; }
        .brand small { display: block; font-size: 9pt; color: #666; font-weight: 400; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 14pt; margin: 0; color: #9a3412; }
        .doc-title .num { font-family: 'Courier New', monospace; font-size: 11pt; }
        h2 { font-size: 11.5pt; margin: 16px 0 6px; padding: 4px 8px; background: #fff3e0; color: #9a3412; border-left: 4px solid #c2410c; }
        table { width: 100%; border-collapse: collapse; }
        .money td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 10.5pt; }
        .money td.r { text-align: right; font-family: 'Courier New', monospace; }
        .money td.lbl { color: #444; }
        .money tr.sub td { font-size: 9.5pt; color: #777; }
        .money tr.total td { border-top: 2px solid #c2410c; font-weight: 800; font-size: 11.5pt; padding-top: 7px; }
        .result { margin-top: 14px; padding: 12px 14px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .result.kb { background: #fef3c7; color: #92400e; } .result.lb { background: #dcfce7; color: #166534; }
        .result .amt { font-family: 'Courier New', monospace; font-size: 16pt; font-weight: 800; }
        .info td { padding: 3px 8px; font-size: 10pt; } .info td.l { color: #666; width: 30%; }
        .signatures { display: flex; justify-content: flex-end; margin-top: 40px; }
        .sign-box { text-align: center; width: 45%; }
        .sign-line { margin-top: 60px; border-top: 1px solid #333; padding-top: 4px; font-size: 10pt; }
        .footer { margin-top: 24px; text-align: center; font-size: 8.5pt; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
        .print-bar { position: fixed; top: 0; left: 0; right: 0; background: #c2410c; color: #fff; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; z-index: 999; }
        .print-bar button { background: #fff; color: #c2410c; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 11pt; }
        @media print { .print-bar { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
@php
    $fmt = fn ($v) => number_format((float) ($v ?? 0), 0, ',', '.');
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month] ?? $month;
    $kurangBayar = $selisih >= 0;
@endphp
    <div class="print-bar"><span>📄 SPT Masa PPN siap dicetak — Ctrl+P (Save as PDF)</span><button onclick="window.print()">🖨 Cetak / Simpan PDF</button></div>
    <div style="height: 44px;"></div>

    <div class="header">
        <div class="brand">{{ $company['name'] }}<small>NPWP: {{ $company['npwp'] }} · {{ $company['address'] }}</small></div>
        <div class="doc-title"><h1>SPT MASA PPN</h1><div class="num">Formulir 1111 — Masa {{ $masaRoman }} ({{ $bulan }}) {{ $year }}</div></div>
    </div>

    <table class="info">
        <tr><td class="l">Masa Pajak</td><td>{{ $bulan }} {{ $year }}</td><td class="l">Pembetulan ke</td><td>0 (Normal)</td></tr>
    </table>

    <h2>I. Penyerahan Barang & Jasa (Pajak Keluaran)</h2>
    <table class="money">
        <tr><td class="lbl">DPP Penyerahan</td><td class="r">{{ $fmt($penyerahan['dpp']) }}</td></tr>
        <tr><td class="lbl">PPN Keluaran (dipungut)</td><td class="r">{{ $fmt($penyerahan['ppn']) }}</td></tr>
        <tr class="sub"><td>— dari Invoice Piutang (AR)</td><td class="r">{{ $fmt($breakdown['ar_ppn']) }}</td></tr>
        <tr class="sub"><td>— dari Penjualan Properti</td><td class="r">{{ $fmt($breakdown['prop_ppn']) }}</td></tr>
    </table>

    <h2>II. Perolehan Barang & Jasa (Pajak Masukan)</h2>
    <table class="money">
        <tr><td class="lbl">DPP Perolehan</td><td class="r">{{ $fmt($perolehan['dpp']) }}</td></tr>
        <tr><td class="lbl">PPN Masukan (dapat dikreditkan)</td><td class="r">{{ $fmt($perolehan['ppn']) }}</td></tr>
    </table>

    <h2>III. Perhitungan PPN Kurang/(Lebih) Bayar</h2>
    <table class="money">
        <tr><td class="lbl">PPN Keluaran</td><td class="r">{{ $fmt($penyerahan['ppn']) }}</td></tr>
        <tr><td class="lbl">PPN Masukan</td><td class="r">({{ $fmt($perolehan['ppn']) }})</td></tr>
        <tr class="total"><td>PPN {{ $kurangBayar ? 'Kurang Bayar (disetor)' : 'Lebih Bayar (kompensasi)' }}</td><td class="r">{{ $fmt(abs($selisih)) }}</td></tr>
    </table>

    <div class="result {{ $kurangBayar ? 'kb' : 'lb' }}">
        <span>{{ $kurangBayar ? 'PPN KURANG BAYAR — wajib disetor ke kas negara' : 'PPN LEBIH BAYAR — dapat dikompensasi ke masa berikutnya' }}</span>
        <span class="amt">Rp {{ $fmt(abs($selisih)) }}</span>
    </div>

    <div class="signatures">
        <div class="sign-box">
            <div>{{ $company['address'] }}, {{ now()->format('d M Y') }}</div>
            <div>Pengusaha Kena Pajak / Kuasa</div>
            <div class="sign-line">{{ $company['name'] }}</div>
        </div>
    </div>

    <div class="footer">Dicetak dari Java Core System · {{ now()->format('d M Y H:i') }} · Rekap dari modul Faktur Pajak (AR + Penjualan Properti + AP).</div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
