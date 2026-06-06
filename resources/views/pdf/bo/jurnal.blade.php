{{-- PDF Template: Voucher Jurnal BO v1.0 — 2026-05-31 --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $jurnal->no_jurnal }}</title>
    <style>
        * { font-family: DejaVu Sans, Arial, sans-serif; }
        body { font-size: 12px; color: #111; margin: 24px; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; }
        .title { font-size: 18px; font-weight: bold; }
        .meta { margin: 12px 0; }
        .meta td { padding: 2px 8px 2px 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lines th, table.lines td { border: 1px solid #999; padding: 6px 8px; }
        table.lines th { background: #f0f0f0; text-align: left; }
        .num { text-align: right; }
        .total td { font-weight: bold; background: #fafafa; }
        .sign { margin-top: 48px; display: flex; justify-content: space-between; }
        .sign div { text-align: center; width: 30%; }
        .badge { padding: 2px 8px; border: 1px solid #111; font-weight: bold; }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <div class="title">{{ $jurnal->company->name ?? 'J-ERP' }}</div>
            <div>Voucher Jurnal — {{ $jurnal->jenis_dokumen }}</div>
        </div>
        <div style="text-align:right">
            <div class="title">{{ $jurnal->no_jurnal }}</div>
            <span class="badge">{{ $jurnal->status }}</span>
        </div>
    </div>

    <table class="meta">
        <tr>
            <td>Tanggal</td><td>: {{ $jurnal->tgl_jurnal->format('d M Y') }}</td>
            <td style="padding-left:32px">No Dokumen</td><td>: {{ $jurnal->no_dokumen ?? '—' }}</td>
        </tr>
        <tr>
            <td>Periode</td><td>: {{ $jurnal->periode }}</td>
            <td style="padding-left:32px">Keterangan</td><td>: {{ $jurnal->keterangan ?? '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th style="width:40px">No</th><th>Akun</th><th>Keterangan</th><th class="num">Debet</th><th class="num">Kredit</th></tr>
        </thead>
        <tbody>
            @foreach ($jurnal->detil as $line)
                <tr>
                    <td>{{ $line->urutan }}</td>
                    <td>{{ $line->kode_account }}</td>
                    <td>{{ $line->keterangan }}</td>
                    <td class="num">{{ number_format($line->debet, 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->kredit, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">TOTAL</td>
                <td class="num">{{ number_format($jurnal->total_debet, 2, ',', '.') }}</td>
                <td class="num">{{ number_format($jurnal->total_kredit, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <div class="sign">
        <div>Dibuat<br><br><br>(__________)</div>
        <div>Diperiksa<br><br><br>(__________)</div>
        <div>Disetujui<br><br><br>(__________)</div>
    </div>
</body>
</html>
