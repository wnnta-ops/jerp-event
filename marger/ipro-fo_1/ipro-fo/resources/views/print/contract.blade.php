<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form Kontrak — {{ $contract->contract_number }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 11pt; color: #1a1a1a; line-height: 1.5; margin: 0;
        }
        .header {
            display: flex; justify-content: space-between; align-items: flex-start;
            border-bottom: 3px solid #ff8c00; padding-bottom: 12px; margin-bottom: 20px;
        }
        .brand { font-size: 18pt; font-weight: 800; color: #ff8c00; }
        .brand small { display: block; font-size: 9pt; color: #666; font-weight: 400; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 15pt; margin: 0; color: #1a1a1a; }
        .doc-title .num { font-family: 'Courier New', monospace; font-size: 12pt; color: #333; }
        .doc-title .badge {
            display: inline-block; margin-top: 4px; padding: 2px 10px; border-radius: 12px;
            font-size: 9pt; background: #fff3e0; color: #c2410c; font-weight: 600;
        }
        h2 {
            font-size: 12pt; margin: 18px 0 8px; padding-bottom: 4px;
            border-bottom: 1px solid #ddd; color: #9a3412;
        }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 4px 8px; vertical-align: top; font-size: 10.5pt; }
        .info td.label { color: #666; width: 28%; }
        .info td.val { font-weight: 600; }
        .grid2 { display: flex; gap: 24px; }
        .grid2 > div { flex: 1; }
        .money-table { margin-top: 6px; }
        .money-table td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 10.5pt; }
        .money-table td.r { text-align: right; font-family: 'Courier New', monospace; }
        .money-table tr.total td { border-top: 2px solid #ff8c00; border-bottom: none; font-weight: 800; font-size: 12pt; padding-top: 8px; }
        .clause { margin-bottom: 10px; }
        .clause .title { font-weight: 700; }
        .clause .body { color: #333; font-size: 10pt; margin-top: 2px; }
        .gimmick td { padding: 4px 8px; border-bottom: 1px solid #eee; font-size: 10pt; }
        .gimmick th { padding: 5px 8px; background: #fff3e0; text-align: left; font-size: 9.5pt; color: #9a3412; }
        .notes { background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 10px 12px; font-size: 10pt; white-space: pre-wrap; }
        .signatures { display: flex; justify-content: space-between; margin-top: 50px; }
        .sign-box { text-align: center; width: 40%; }
        .sign-line { margin-top: 60px; border-top: 1px solid #333; padding-top: 4px; font-size: 10pt; }
        .footer { margin-top: 30px; text-align: center; font-size: 8.5pt; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
        .print-bar {
            position: fixed; top: 0; left: 0; right: 0; background: #ff8c00; color: #fff;
            padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; z-index: 999;
        }
        .print-bar button {
            background: #fff; color: #c2410c; border: none; padding: 8px 16px; border-radius: 6px;
            font-weight: 700; cursor: pointer; font-size: 11pt;
        }
        @media print { .print-bar { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>
    @php
        $b = $contract->booking;
        $snap = $contract->snapshot ?? [];
        $fmt = fn ($v) => 'Rp ' . number_format((float) ($v ?? 0), 0, ',', '.');
        $companyName = $company['name'] ?? 'PT. Mitra Usaha Propertindo';
    @endphp

    <div class="print-bar">
        <span>📄 Form Kontrak siap dicetak — gunakan tombol di kanan atau Ctrl+P (pilih "Save as PDF")</span>
        <button onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    </div>

    <div style="height: 44px;"></div>

    <div class="header">
        <div class="brand">
            Java Core System
            <small>{{ $companyName }}</small>
        </div>
        <div class="doc-title">
            <h1>FORM KONTRAK</h1>
            <div class="num">{{ $contract->contract_number }}</div>
            <span class="badge">PPJB: {{ $contract->ppjb_status?->label() ?? '-' }}</span>
        </div>
    </div>

    <h2>Data Kontrak</h2>
    <div class="grid2">
        <div>
            <table class="info">
                <tr><td class="label">No. Kontrak</td><td class="val">{{ $contract->contract_number }}</td></tr>
                <tr><td class="label">No. Booking</td><td class="val">{{ $b?->booking_number ?? '-' }}</td></tr>
                <tr><td class="label">Customer</td><td class="val">{{ $b?->customer?->full_name ?? '-' }}</td></tr>
                <tr><td class="label">Unit</td><td class="val">{{ $b?->unit?->unit_code ?? '-' }}</td></tr>
            </table>
        </div>
        <div>
            <table class="info">
                <tr><td class="label">Skema Bayar</td><td class="val">{{ $b?->paymentScheme?->name ?? '-' }}</td></tr>
                <tr><td class="label">Status</td><td class="val">{{ ucfirst($contract->status) }}</td></tr>
                <tr><td class="label">No. PPJB</td><td class="val">{{ $effective_ppjb_number ?? '-' }}</td></tr>
                <tr><td class="label">Tgl Tanda Tangan</td><td class="val">{{ $contract->signed_at?->format('d M Y') ?? '-' }}</td></tr>
            </table>
        </div>
    </div>

    <h2>Rincian Harga</h2>
    <table class="money-table">
        <tr><td>Harga Dasar</td><td class="r">{{ $fmt($snap['base_price'] ?? $b?->base_price) }}</td></tr>
        <tr><td>Diskon</td><td class="r">{{ $fmt($snap['discount'] ?? $b?->discount_amount) }}</td></tr>
        <tr><td>PPN</td><td class="r">{{ $fmt($snap['ppn'] ?? $b?->ppn_amount) }}</td></tr>
        <tr class="total"><td>TOTAL NILAI KONTRAK</td><td class="r">{{ $fmt($snap['total_price'] ?? $b?->total_price) }}</td></tr>
    </table>

    @if($contract->gimmicks->isNotEmpty())
        <h2>Gimmick / Bonus</h2>
        <table class="gimmick">
            <thead><tr><th>No</th><th>Kode</th><th>Nama Barang</th><th style="text-align:right">Nilai</th></tr></thead>
            <tbody>
                @foreach($contract->gimmicks as $i => $g)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $g->gimmick?->code }}</td>
                        <td>{{ $g->gimmick?->name }}</td>
                        <td style="text-align:right;font-family:monospace">{{ $fmt($g->total_harga) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($contract->clauses->isNotEmpty())
        <h2>Pasal-Pasal Kontrak</h2>
        @foreach($contract->clauses as $cl)
            <div class="clause">
                <div class="title">Pasal {{ $cl->clause_no }}: {{ $cl->title }}</div>
                <div class="body">{{ $cl->body }}</div>
            </div>
        @endforeach
    @endif

    @if($contract->notes)
        <h2>Catatan</h2>
        <div class="notes">{{ $contract->notes }}</div>
    @endif

    <div class="signatures">
        <div class="sign-box">
            <div>Pihak Pertama (Developer)</div>
            <div class="sign-line">{{ $companyName }}</div>
        </div>
        <div class="sign-box">
            <div>Pihak Kedua (Pembeli)</div>
            <div class="sign-line">{{ $b?->customer?->full_name ?? '(.....................)' }}</div>
        </div>
    </div>

    <div class="footer">
        Dicetak dari Java Core System v2.0 pada {{ now()->format('d M Y H:i') }} ·
        Dokumen ini sah tanpa tanda tangan basah jika diterbitkan oleh sistem.
    </div>

    <script>
        // Auto-buka dialog print setelah halaman selesai load.
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
