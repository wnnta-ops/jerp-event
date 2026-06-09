<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>SPRS — {{ $reg->no_nup ?: $reg->nama_pendaftar }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 28px 34px; }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .head .co { text-align: center; flex: 1; }
        .head .co .pt { font-weight: 800; font-size: 13px; letter-spacing: .3px; }
        .head .co .ttl { font-weight: 700; font-size: 12px; }
        .head .src { border: 1px solid #111; width: 160px; height: 56px; font-size: 10px; }
        .head .src .lbl { border-bottom: 1px solid #111; text-align: center; font-weight: 700; padding: 3px; }
        .head .src .val { padding: 4px 6px; }
        .brand { width: 120px; font-weight: 800; color: #ea7a1d; font-size: 16px; line-height: 1.05; }
        table.f { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.f td { padding: 2px 4px; vertical-align: top; }
        table.f td.k { width: 150px; }
        table.f td.s { width: 10px; }
        .sec { margin-top: 12px; border: 1px solid #111; }
        .sec .h { background: #f1f1f1; font-weight: 800; text-align: center; border-bottom: 1px solid #111; padding: 4px; }
        .sec table { width: 100%; border-collapse: collapse; }
        .sec table td { padding: 4px 8px; border-bottom: 1px solid #e2e2e2; }
        .notes { margin-top: 14px; font-size: 11px; }
        ol.kk { padding-left: 18px; margin: 4px 0; }
        ol.kk li { margin-bottom: 4px; }
        .sign { display: flex; justify-content: space-between; margin-top: 36px; text-align: center; font-size: 12px; }
        .sign .c { width: 32%; }
        .sign .line { margin-top: 48px; border-top: 1px solid #111; padding-top: 4px; }
        .actions { margin-top: 18px; }
        @media print { .actions { display: none; } body { padding: 12px; } }
        .btn { display:inline-block; padding:8px 16px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; }
    </style>
</head>
<body>
    @php
        $c = $reg->customer;
        $nama = $c?->full_name ?: $reg->nama_pendaftar;
        $alamat = $c?->alamatRingkas() ?: ($reg->keterangan ?: '—');
        $sales = $reg->agent?->sales_label ?: ($reg->ref_sales_name ?: '—');
        $kpr = $reg->cara_bayar === 'KPR';
    @endphp
    <div class="head">
        <div class="brand">JAVA<br>RESIDENCE</div>
        <div class="co">
            <div class="pt">PT. MITRA USAHA PROPERTINDO</div>
            <div class="ttl">FORMULIR SURAT PEMESANAN RUMAH SEMENTARA</div>
        </div>
        <div class="src">
            <div class="lbl">Sumber Informasi</div>
            <div class="val">{{ $sales }}</div>
        </div>
    </div>

    <table class="f">
        <tr><td class="k">*Nama</td><td class="s">:</td><td>{{ $nama ?: '—' }}</td></tr>
        <tr><td class="k">*Alamat</td><td class="s">:</td><td>{{ $alamat }}</td></tr>
        <tr><td class="k">*No KTP</td><td class="s">:</td><td>{{ $c?->nik ?: $reg->nik ?: '—' }}</td></tr>
        <tr><td class="k">*NPWP</td><td class="s">:</td><td>{{ $c?->npwp ?: '—' }}</td></tr>
        <tr><td class="k">*Alamat Surat</td><td class="s">:</td><td>{{ $c?->mail_address ?: $alamat }}</td></tr>
        <tr><td class="k">*Telp / HP</td><td class="s">:</td><td>{{ $reg->phone ?: ($c?->mobile ?: '—') }}</td></tr>
        <tr><td class="k">*Type / Pembelian</td><td class="s">:</td><td>{{ $reg->tipe_nup ?: 'RUMAH' }}</td></tr>
        <tr><td class="k">*Pilihan Unit 1</td><td class="s">:</td><td>{{ $reg->pilihan_unit_1 ?: '—' }}</td></tr>
        <tr><td class="k">*Pilihan Unit 2</td><td class="s">:</td><td>{{ $reg->pilihan_unit_2 ?: '—' }}</td></tr>
        <tr><td class="k">*Pilihan Unit 3</td><td class="s">:</td><td>{{ $reg->pilihan_unit_3 ?: '—' }}</td></tr>
        <tr><td class="k">*Cara Bayar</td><td class="s">:</td><td>{{ $reg->cara_bayar ?: '—' }}</td></tr>
        <tr><td class="k">*Email</td><td class="s">:</td><td>{{ $c?->email ?: $reg->email ?: '—' }}</td></tr>
        <tr><td class="k">*Sales / Agen</td><td class="s">:</td><td>{{ $sales }}</td></tr>
        <tr><td class="k">No. NUP</td><td class="s">:</td><td><b>{{ $reg->no_nup ?: '—' }}</b> @if ($reg->urutan) &nbsp; Urutan {{ $reg->urutan }} @endif</td></tr>
    </table>

    <div class="sec">
        <div class="h">{{ $kpr ? '*Pembayaran KPR' : '*Pembayaran Cash' }}</div>
        <table>
            <tr><td style="width:220px;">Tanda Jadi (UTJ)</td><td>: Rp {{ number_format((float) $reg->utj_amount, 0, ',', '.') }}</td></tr>
            <tr><td>Status Pembayaran UTJ</td><td>: {{ $reg->xendit_status ?: ($reg->isUtjPaid() ? 'PAID' : 'MENUNGGU') }}</td></tr>
        </table>
    </div>

    <div class="notes">
        <b>Syarat dan Ketentuan :</b>
        <ol class="kk">
            <li>Untuk mendapatkan promo <i>free DP</i>, dokumen lengkap (siap proses KPR) diserahkan paling lambat 10 hari kalender sejak penandatanganan surat ini; apabila melebihi waktu tersebut, maka Uang Tanda Jadi (UTJ) dialokasikan sebagai bagian DP.</li>
            <li>Uang Tanda Jadi (UTJ) dapat dikembalikan apabila hasil SLIK COLL 3-5 dan sebelum diterbitkannya SPR.</li>
            <li>Pembayaran wajib di transfer ke : BCA No. Rek 6155899909 an. PT. Mitra Usaha Propertindo.</li>
            <li>Formulir Pesanan Tanah &amp; Bangunan ini memiliki kekuatan hukum mengikat kedua belah pihak.</li>
        </ol>
    </div>

    <div style="text-align:right; margin-top:10px;">Sidoarjo, {{ now()->format('d M Y') }}</div>
    <div class="sign">
        <div class="c"><div class="line">{{ $nama ?: '' }}<br>Pembeli</div></div>
        <div class="c"><div class="line">{{ $sales }}<br>Marketing</div></div>
        <div class="c"><div class="line">PT. MUP</div></div>
    </div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
