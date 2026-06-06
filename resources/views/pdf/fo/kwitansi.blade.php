<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi — {{ $bkm->no_bkm }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 13px; margin: 0; padding: 40px 48px; }
        .logo { width: 56px; height: 56px; background: #ea7a1d; color: #fff; font-weight: 900; font-size: 26px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .head { text-align: center; margin-top: -56px; }
        .head .t { font-weight: 800; font-size: 16px; letter-spacing: 1px; }
        .head .n { font-weight: 700; margin-top: 2px; }
        hr { border: 0; border-top: 1px solid #111; margin: 16px 0; }
        table.k { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.k td { padding: 5px 4px; vertical-align: top; }
        table.k td.l { width: 200px; color: #ea7a1d; font-weight: 600; }
        table.k td.s { width: 12px; }
        .jumlah { margin-top: 28px; border-top: 1px solid #111; display: inline-block; padding-top: 4px; min-width: 280px; }
        .right { text-align: right; margin-top: 6px; }
        .pays { margin-top: 28px; display: flex; gap: 36px; }
        .pays .p { display: flex; align-items: center; gap: 6px; }
        .box { width: 12px; height: 12px; border: 1px solid #111; display: inline-block; }
        .box.on { background: #111; }
        .nb { margin-top: 32px; font-size: 11px; font-weight: 700; }
        .foot { margin-top: 8px; font-size: 11px; color: #555; }
        .actions { margin-top: 18px; }
        @media print { .actions { display: none; } body { padding: 16px; } }
        .btn { display:inline-block; padding:8px 16px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; }
    </style>
</head>
<body>
    @php
        $cara = strtoupper((string) ($bkm->tts?->cara_bayar ?: ''));
        $isTransfer = in_array($cara, ['TRANSFER', 'XENDIT', 'VA'], true);
        $isGiro = in_array($cara, ['GIRO', 'CHEQUE'], true);
        $isTunai = $cara === 'CASH';
        $unitKode = $bkm->kontrak?->unit?->kode_unit;
    @endphp
    <div class="logo">M</div>
    <div class="head">
        <div class="t">KWITANSI</div>
        <div class="n">{{ $bkm->no_bkm }}</div>
    </div>
    <hr>

    <table class="k">
        <tr><td class="l">Telah Diterima Dari</td><td class="s">:</td><td><b>{{ $bkm->customer?->full_name ?: '—' }}</b></td></tr>
        <tr><td class="l">Sebesar</td><td class="s">:</td><td><b>Rp {{ number_format((float) $bkm->amount, 0, ',', '.') }}</b></td></tr>
        <tr><td class="l">Terbilang</td><td class="s">:</td><td><i>{{ $terbilang }}</i></td></tr>
        <tr><td class="l">Pembayaran</td><td class="s">:</td><td>{{ $bkm->jenis_pembayaran }}{{ $bkm->kontrak ? ' — Kontrak '.$bkm->kontrak->no_kontrak : '' }}</td></tr>
        @if ($unitKode)
            <tr><td class="l">Kode Unit</td><td class="s">:</td><td>{{ $unitKode }}</td></tr>
        @endif
    </table>

    <div class="jumlah"><b>Rp {{ number_format((float) $bkm->amount, 0, ',', '.') }}</b></div>

    <div class="pays">
        <div class="p"><span class="box {{ $isTransfer ? 'on' : '' }}"></span> Transfer</div>
        <div class="p"><span class="box {{ $isGiro ? 'on' : '' }}"></span> Giro/Cek</div>
        <div class="p"><span class="box {{ $isTunai ? 'on' : '' }}"></span> Tunai</div>
        <div style="flex:1"></div>
        <div>Sidoarjo, {{ optional($bkm->tgl_bkm)->format('d M Y') }}</div>
    </div>

    <div class="nb">NB. Pembayaran dengan Bilyet Giro / Cheque dianggap sah jika sudah dicairkan / diuangkan.</div>
    <div class="foot">Kwitansi menggantikan Tanda Terima Sementara{{ $bkm->tts ? ' ('.$bkm->tts->no_tts.')' : '' }}.</div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
