<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi UTJ — {{ $reg->nama_pendaftar }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 24px 32px; }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .head .co { text-align: center; flex: 1; }
        .head .co .pt { font-weight: 800; font-size: 13px; letter-spacing: .3px; }
        .brand { width: 130px; font-weight: 800; color: #ea7a1d; font-size: 16px; line-height: 1.05; }
        .judul { border-bottom: 2px solid #111; padding-bottom: 10px; text-align: center; margin-top: 6px; }
        .judul div { font-weight: 800; font-size: 13px; }
        table.f { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.f td { padding: 2px 4px; vertical-align: top; }
        table.f td.k { width: 180px; }
        table.f td.s { width: 10px; }
        .jml { margin-top: 18px; display: inline-block; border: 1px solid #111; padding: 8px 16px; font-weight: 800; font-size: 14px; }
        .nb { margin-top: 26px; font-size: 10px; color: #333; }
        .actions { margin-top: 24px; }
        .actions .btn { display: inline-block; background: #ea7a1d; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; margin-right: 8px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
@php
    $c = $reg->customer;
    $rp = fn ($v) => 'Rp. '.number_format((float) $v, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y') : '—';

    $logoPath = public_path('images/logo/logo-cop.png');
    $logoSrc = is_file($logoPath)
        ? 'data:'.(@mime_content_type($logoPath) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($logoPath))
        : null;

    $nama = $c?->full_name ?: $reg->nama_pendaftar;

    // NOMOR = external id Xendit (nomor transfer). Inilah rujukan yang sama dengan
    // yang tampil di dashboard Xendit, sehingga kwitansi mudah dicocokkan dengan
    // mutasi. Fallback hanya dipakai bila pembayaran dicatat di luar Xendit.
    $kwNo = $reg->xendit_external_id ?: ($reg->no_nup ?: ('NUP-'.$reg->id));

    // Nominal yang BENAR-BENAR dibayar bila Xendit melaporkannya; kalau tidak,
    // jatuh ke nilai UTJ yang ditagihkan.
    $kwAmount = (float) ($reg->xendit_paid_amount ?: ($reg->booking_fee_paid ?: $reg->utj_amount));
    $kwTgl = $reg->xendit_paid_at ?: ($reg->tgl_payment ?: now());

    // UNTUK PEMBAYARAN = nama acara launching. Pada tahap ini nomor NUP belum
    // terbit (baru muncul setelah verifikasi disetujui), jadi acaralah penanda
    // yang bisa dipakai customer maupun petugas.
    $kwUntuk = $reg->event?->name ?: ($reg->projectPref?->name ?: 'Uang Tanda Jadi (UTJ)');

    $terbilang = \App\Support\Terbilang::rupiah($kwAmount);
@endphp

    <div class="head">
        @if ($logoSrc)
            <img src="{{ $logoSrc }}" alt="PT Mitra Usaha Propertindo" style="max-width:150px; max-height:58px; object-fit:contain;">
        @else
            <div class="brand">JAVA<br>RESIDENCE</div>
        @endif
        <div class="co">
            <div class="pt">PT. MITRA USAHA PROPERTINDO</div>
        </div>
        <div style="width:150px;"></div>
    </div>

    <div class="judul"><div>KWITANSI</div></div>

    <table class="f">
        <tr><td class="k">NOMOR</td><td class="s">:</td><td>{{ $kwNo }}</td></tr>
        <tr><td class="k">TELAH DITERIMA DARI</td><td class="s">:</td><td>{{ $nama ?: '—' }}</td></tr>
        <tr><td class="k">UANG SEJUMLAH</td><td class="s">:</td><td><i>{{ $terbilang }}</i></td></tr>
        <tr><td class="k">UNTUK PEMBAYARAN</td><td class="s">:</td><td>{{ $kwUntuk }}</td></tr>
    </table>

    <div style="text-align:right; margin-top:14px;">SIDOARJO, {{ $tgl($kwTgl) }}</div>

    <div class="jml">JUMLAH &nbsp; {{ $rp($kwAmount) }}</div>

    <div style="text-align:right; margin-top:30px;">Finance<br>
        <span style="display:inline-block; border-top:1px solid #111; width:160px; margin-top:36px;"></span>
    </div>

    <div class="nb">
        NB : Pembayaran berupa cek/giro dianggap sah apabila dana tersebut telah cair<br>
        Lembar Putih : Customer &nbsp; Lembar Merah : Administrasi &nbsp; Lembar Kuning : Finance
    </div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
