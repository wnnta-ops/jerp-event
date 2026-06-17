<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>SPRS — {{ $reg->no_nup ?: $reg->nama_pendaftar }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 24px 32px; }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .head .co { text-align: center; flex: 1; }
        .head .co .pt { font-weight: 800; font-size: 13px; letter-spacing: .3px; }
        .head .co .ttl { font-weight: 700; font-size: 12px; }
        .head .src { border: 1px solid #111; width: 170px; height: 58px; font-size: 10px; }
        .head .src .lbl { border-bottom: 1px solid #111; text-align: center; font-weight: 700; padding: 3px; }
        .head .src .val { padding: 4px 6px; min-height: 30px; }
        .brand { width: 130px; font-weight: 800; color: #ea7a1d; font-size: 16px; line-height: 1.05; }
        table.f { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.f td { padding: 1.5px 4px; vertical-align: top; }
        table.f td.k { width: 150px; }
        table.f td.s { width: 10px; }
        .chk { display: inline-block; width: 11px; height: 11px; border: 1px solid #111; margin-right: 4px; vertical-align: middle; text-align: center; line-height: 10px; font-size: 10px; }
        .note-it { text-align: right; font-style: italic; font-size: 11px; margin-top: 4px; }
        table.pay { width: 100%; border-collapse: collapse; margin-top: 10px; border: 1px solid #111; }
        table.pay td, table.pay th { border: 1px solid #111; padding: 3px 6px; font-size: 11px; vertical-align: top; }
        table.pay th { background: #f1f1f1; text-align: center; }
        .pengajuan { margin: 8px 0 2px; font-size: 11px; }
        .biaya { margin-top: 12px; font-size: 11px; }
        .biaya div { font-weight: 800; font-style: italic; margin: 2px 0; }
        .kk { margin-top: 12px; font-size: 11px; }
        .kk .h { font-weight: 800; font-style: italic; margin-bottom: 4px; }
        ol.terms { padding-left: 18px; margin: 4px 0; }
        ol.terms li { margin-bottom: 4px; }
        .sign { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; font-size: 12px; }
        .sign .c { width: 32%; }
        .sign .line { margin-top: 44px; border-top: 1px solid #111; padding-top: 4px; }
        .actions { margin-top: 18px; }
        @media print { .actions { display: none; } body { padding: 12px; } }
        .btn { display: inline-block; padding: 8px 16px; background: #2563eb; color: #fff; border-radius: 8px; text-decoration: none; font-size: 13px; }
    </style>
</head>
<body>
    @php
        $c = $reg->customer;
        $k = $kontrak ?? null;
        $unit = $k?->unit;
        $ut = $unit?->unitType;

        $nama = $c?->full_name ?: $reg->nama_pendaftar;
        $alamat = $c?->alamatRingkas() ?: ($reg->keterangan ?: '—');
        // Marketing = sales kontrak (utama), fallback ke agent/ref pendaftaran.
        $sales = $k?->agent?->sales_label ?: ($k?->agent?->name
            ?: ($reg->agent?->sales_label ?: ($reg->ref_sales_name ?: '—')));
        // Sumber Informasi = Sumber Data pemasaran (master) yang dipilih saat daftar.
        $sumberInfo = $c?->sumber_data_label ?: '—';

        $cara = $k?->cara_bayar ?: ($reg->cara_bayar ?: 'KPR');
        $kpr = $cara === 'KPR';
        $isRuko = str_contains(strtoupper((string) ($ut?->name ?? $reg->tipe_nup ?? '')), 'RUKO');

        $rp = fn ($v) => 'Rp. ' . number_format((float) $v, 0, ',', '.');
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '';

        $pu1 = $unit?->kode_unit ?: ($reg->pilihan_unit_1 ?: '');
        $pu2 = $reg->pilihan_unit_2 ?: '';
        $pu3 = $reg->pilihan_unit_3 ?: '';
        $blok = $unit ? trim(($unit->blok ?: '') . ($unit->no_unit ? ' No. ' . $unit->no_unit : '')) : '';
        $lt = $unit?->luas_tanah ?: $ut?->luas_tanah;
        $lb = $ut?->luas_bangunan;
        $ltlb = ($lt || $lb) ? (($lt ?: '-') . ' / ' . ($lb ?: '-')) : '';

        $hargaMengikat = $k ? $rp($k->total_kontrak) : '';
        $tandaJadi = (float) ($k?->reservasi?->booking_fee ?: ($reg->booking_fee_paid ?: $reg->utj_amount));
        $totalUM = (float) ($k?->dp_amount ?: 0);
        $email = $c?->email ?: '';
        $catatan = $k?->keterangan ?: ($reg->keterangan ?: '');
        $tglSurat = $k?->tgl_kontrak ?: now();
    @endphp

    <div class="head">
        <div class="brand">JAVA<br>RESIDENCE</div>
        <div class="co">
            <div class="pt">PT. MITRA USAHA PROPERTINDO</div>
            <div class="ttl">FORMULIR SURAT PEMESANAN RUMAH SEMENTARA</div>
        </div>
        <div class="src">
            <div class="lbl">Sumber Informasi</div>
            <div class="val">{{ $sumberInfo !== '—' ? $sumberInfo : '' }}</div>
        </div>
    </div>

    <table class="f">
        <tr><td class="k">*Nama</td><td class="s">:</td><td>{{ $nama ?: '—' }}</td></tr>
        <tr><td class="k">*Alamat</td><td class="s">:</td><td>{{ $alamat }}</td></tr>
        <tr><td class="k">*No KTP</td><td class="s">:</td><td>{{ $c?->nik ?: $reg->nik ?: '' }}</td></tr>
        <tr><td class="k">*NPWP</td><td class="s">:</td><td>{{ $c?->npwp ?: '' }}</td></tr>
        <tr><td class="k">*Alamat Surat</td><td class="s">:</td><td>{{ $c?->mail_address ?: $alamat }}</td></tr>
        <tr><td class="k">*Telp / HP</td><td class="s">:</td><td>{{ $reg->phone ?: ($c?->mobile ?: '') }}</td></tr>
        <tr><td class="k">Pembelian</td><td class="s">:</td><td>
            <span class="chk">{!! $isRuko ? '' : '&#10003;' !!}</span> Rumah &nbsp;&nbsp;&nbsp;
            <span class="chk">{!! $isRuko ? '&#10003;' : '' !!}</span> Ruko
        </td></tr>
        <tr><td class="k">*Type</td><td class="s">:</td><td>{{ $reg->tipe_nup ?: ($ut?->name ?: 'RUMAH') }}</td></tr>
        <tr><td class="k">*Pilihan Unit 1</td><td class="s">:</td><td>{{ $pu1 }}</td></tr>
        <tr><td class="k">*Pilihan Unit 2</td><td class="s">:</td><td>{{ $pu2 }}</td></tr>
        <tr><td class="k">*Pilihan Unit 3</td><td class="s">:</td><td>{{ $pu3 }}</td></tr>
        <tr><td class="k">*Blok No.</td><td class="s">:</td><td>{{ $blok }}</td></tr>
        <tr><td class="k">*LT / LB</td><td class="s">:</td><td>{{ $ltlb }}</td></tr>
        <tr><td class="k">*Peruntukan</td><td class="s">:</td><td></td></tr>
        <tr><td class="k">*Harga Mengikat</td><td class="s">:</td><td>{{ $hargaMengikat }}</td></tr>
        <tr><td class="k">*Cara Bayar</td><td class="s">:</td><td>{{ $cara }}</td></tr>
        <tr><td class="k">*Promo</td><td class="s">:</td><td>{{ $k?->gimmicks?->pluck('nama')->filter()->implode(', ') }}</td></tr>
        <tr><td class="k">*Email</td><td class="s">:</td><td>{{ $email }}</td></tr>
        <tr><td class="k">*Nama Bank</td><td class="s">:</td><td></td></tr>
    </table>
    <div class="note-it">*Mohon untuk isi dengan lengkap</div>

    {{-- Pembayaran KPR --}}
    <table class="pay">
        <tr><th colspan="2">*Pembayaran KPR</th><th>(bulan)</th></tr>
        <tr><td style="width:150px;">Tanda Jadi</td><td style="width:45%;">: {{ $kpr ? $rp($tandaJadi) : 'Rp.' }}</td><td>Tanggal {{ $kpr ? $tgl($tglSurat) : '' }}</td></tr>
        <tr><td>Total UM</td><td>: {{ $kpr && $totalUM > 0 ? $rp($totalUM) : '' }}</td><td></td></tr>
        <tr><td>Jumlah Angsuran</td><td>:</td><td>Dibayar setiap tanggal</td></tr>
    </table>
    <div class="pengajuan">PENGAJUAN KPR : &nbsp;&nbsp;&nbsp;&nbsp; KE :</div>

    {{-- Pembayaran Cash --}}
    <table class="pay">
        <tr><th colspan="2">*Pembayaran Cash</th><th>(bulan)</th></tr>
        <tr><td style="width:150px;">Tanda Jadi</td><td style="width:45%;">: {{ ! $kpr ? $rp($tandaJadi) : 'Rp.' }}</td><td>Tanggal {{ ! $kpr ? $tgl($tglSurat) : '' }}</td></tr>
        <tr><td>Pembayaran 1</td><td>:</td><td>Tanggal</td></tr>
        <tr><td>Pembayaran 2</td><td>:</td><td>Tanggal</td></tr>
        <tr><td>Pembayaran 3</td><td>:</td><td>Tanggal</td></tr>
    </table>

    <table class="f" style="margin-top:10px;">
        <tr><td class="k">Catatan</td><td class="s">:</td><td>{{ $catatan }}</td></tr>
    </table>

    <div class="biaya">
        <div>*BIAYA PAJAK :</div>
        <div>*BIAYA :</div>
        <div>*BIAYA LAIN-LAIN :</div>
    </div>

    <div class="kk">
        <div class="h">Syarat dan Ketentuan :</div>
        <ol class="terms">
            <li>Untuk mendapatkan promo <i>free DP</i>, dokumen lengkap (siap proses KPR) diserahkan paling lambat 10 hari kalender
                sejak penandatangan surat ini, apabila melebihi waktu tersebut, maka Uang Tanda Jadi (UTJ) dialokasikan sebagai bagian <i>DP</i>.</li>
            <li>Uang Tanda Jadi (UTJ) dapat dikembalikan apabila hasil SLIK <i>COLL</i> 3-5 dan sebelum diterbitkannya SPR.</li>
            <li>Pembayaran wajib di transfer ke : BCA No. Rek 6155899909 an. PT. Mitra Usaha Propertindo.</li>
            <li>Formulir Pesanan Tanah &amp; Bangunan ini memiliki kekuatan hukum mengikat kedua belah pihak.</li>
        </ol>
    </div>

    <div style="margin-top:10px;">Sidoarjo, {{ $tgl($tglSurat) }}</div>

    <div class="sign">
        <div class="c"><div class="line">{{ $nama ?: '' }}<br>Pembeli</div></div>
        <div class="c"><div class="line">{{ $sales }}<br>Marketing</div></div>
        <div class="c"><div class="line">PT. MUP</div></div>
    </div>

    {{-- ════════════ HALAMAN 2 — KWITANSI ════════════ --}}
    @php
        $tts = $tts ?? null;
        $kwNo = $tts?->no_tts ?: (($reg->xendit_external_id ?: $reg->no_nup) ?: ('NUP-' . $reg->id));
        $kwAmount = (float) ($tts?->amount ?: ($tandaJadi ?: $reg->utj_amount));
        $kwTgl = $tts?->tgl_terima ?: ($reg->xendit_paid_at ?: now());
        $kwUntuk = $tts?->no_referensi ?: ('Uang Tanda Jadi (UTJ) — NUP ' . ($reg->no_nup ?: $reg->id));
        $terbilang = \App\Support\Terbilang::rupiah($kwAmount);
    @endphp
    <div style="page-break-before: always;"></div>
    <div style="border-bottom:2px solid #111; padding-bottom:10px; text-align:center; margin-top:6px;">
        <div style="font-weight:800; font-size:13px;">KWITANSI</div>
    </div>
    <table class="f" style="margin-top:18px;">
        <tr><td class="k" style="width:180px;">NOMOR</td><td class="s">:</td><td>{{ $kwNo }}</td></tr>
        <tr><td class="k">TELAH DITERIMA DARI</td><td class="s">:</td><td>{{ $nama ?: '—' }}</td></tr>
        <tr><td class="k">UANG SEJUMLAH</td><td class="s">:</td><td><i>{{ $terbilang }}</i></td></tr>
        <tr><td class="k">UNTUK PEMBAYARAN</td><td class="s">:</td><td>{{ $kwUntuk }}</td></tr>
    </table>
    <div style="text-align:right; margin-top:14px;">SIDOARJO, {{ $tgl($kwTgl) }}</div>
    <div style="margin-top:18px; display:inline-block; border:1px solid #111; padding:8px 16px; font-weight:800; font-size:14px;">JUMLAH &nbsp; {{ $rp($kwAmount) }}</div>
    <p style="margin-top:14px; font-size:10px; color:#444;">NB: Pembayaran berupa cek/giro dianggap sah apabila dana tersebut telah cair.</p>
    <p style="font-size:10px; color:#444;">Lembar Putih: Customer &nbsp;·&nbsp; Lembar Merah: Administrasi &nbsp;·&nbsp; Lembar Kuning: Finance</p>
    <div style="text-align:right; margin-top:30px;">Finance<br><span style="display:inline-block; border-top:1px solid #111; width:160px; margin-top:36px;"></span></div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
