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
        .head .right { display: flex; gap: 8px; align-items: flex-start; }
        /* Halaman 3 — QR Customer, seluruhnya rata tengah. */
        .qrpage { text-align: center; margin-top: 90px; }
        .qrpage svg { width: 260px; height: 260px; display: block; margin: 0 auto; }
        .qrpage .nama { font-size: 18px; font-weight: 800; margin-top: 18px; letter-spacing: .3px; }
        .qrpage .event { font-size: 13px; font-weight: 700; margin-top: 6px; }
        .qrpage .nup { font-size: 16px; font-weight: 800; margin-top: 8px; letter-spacing: .8px; }
        .qrpage .ket { font-size: 12px; margin-top: 12px; }
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
        // Nama acara launching — dicetak di halaman QR agar petugas tahu QR ini untuk
        // acara yang mana (satu customer bisa ikut lebih dari satu launching).
        $namaEvent = $reg->event?->name ?: '';
        $alamat = $c?->alamatRingkas() ?: ($reg->keterangan ?: '—');
        // Marketing yang TERCETAK ke customer = sales FREE-TEXT dari NUP. Master sales
        // (agent) hanya fallback bila tak ada free-text (mis. kontrak langsung).
        // Nomor telepon sales SENGAJA tidak diambil — tanda tangan hanya memuat nama.
        $sales = $k?->ref_sales_name ?: ($reg->ref_sales_name
            ?: ($k?->agent?->sales_label ?: ($k?->agent?->name
            ?: ($reg->agent?->sales_label ?: '—'))));
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
        $ltlb = ($lt || $lb) ? (\App\Support\Luas::rapi($lt, '-') . ' / ' . \App\Support\Luas::rapi($lb, '-')) : '';

        $hargaMengikat = $k ? $rp($k->total_kontrak) : '';
        $tandaJadi = (float) ($k?->reservasi?->booking_fee ?: ($reg->booking_fee_paid ?: $reg->utj_amount));
        $totalUM = (float) ($k?->dp_amount ?: 0);
        // Email pendaftaran didahulukan atas email master customer — urutan yang SAMA
        // dengan pengiriman konfirmasi (KirimKonfirmasiNupJob), supaya alamat yang
        // tercetak di SPRS memang alamat yang dikirimi berkasnya. Ini juga yang
        // membuat "Edit SPRS" cukup mengubah data pendaftaran, tanpa menyentuh
        // master customer yang dipakai bersama pendaftaran/kontrak lain.
        $email = $reg->email ?: ($c?->email ?: '');
        $catatan = $k?->keterangan ?: ($reg->keterangan ?: '');
        $tglSurat = $k?->tgl_kontrak ?: now();

        // Teks dari Pengaturan → Template Dokumen (versi yang berlaku pada tanggal surat,
        // cluster unit lebih dulu). $isiDokumen = pratinjau dari layar Template Dokumen.
        $lokasiSprs = $unit?->lokasi_id ?: ($reg->unit_id ? $reg->unit?->lokasi_id : null);
        $dok = array_replace(
            \App\Modules\FO\Komunikasi\Support\DokumenBawaan::sprs(),
            (array) ($isiDokumen ?? \App\Modules\FO\Komunikasi\Models\TemplateDokumen::berlakuUntuk('SPRS', $lokasiSprs, $tglSurat)?->isi ?? []),
        );
        $nilaiDok = [
            'nama_perusahaan' => $dok['nama_perusahaan'],
            'nama_customer' => (string) $nama,
            'no_kontrak' => (string) ($k?->no_kontrak ?: ($reg->no_nup ?: '')),
            'unit' => (string) $pu1,
            'cluster' => (string) ($unit?->lokasi?->nama ?: ''),
            'tanggal' => $tgl($tglSurat),
        ];
        $tx = fn ($teks) => \App\Modules\FO\Komunikasi\Support\DokumenBawaan::teks($teks, $nilaiDok);
    @endphp

    {{-- Logo di-embed base64 supaya ikut tercetak di PDF (Browsershot tak selalu
         bisa mengambil berkas lewat URL). Bila berkas hilang → fallback teks. --}}
    @php
        $logoPath = public_path('images/logo/logo-cop.png');
        $logoSrc = is_file($logoPath)
            ? 'data:'.(@mime_content_type($logoPath) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;
    @endphp

    <div class="head">
        {{-- KIRI ATAS: logo Java Residence. --}}
        @if ($logoSrc)
            <img src="{{ $logoSrc }}" alt="Java Residence" style="max-width:150px; max-height:58px; object-fit:contain;">
        @else
            <div class="brand">JAVA<br>RESIDENCE</div>
        @endif
        <div class="co">
            <div class="pt">{{ $dok['nama_perusahaan'] }}</div>
            <div class="ttl">{{ $dok['judul'] }}</div>
        </div>
        <div class="right">
            {{-- QR Customer dipindah ke HALAMAN 2 (lihat bagian bawah berkas ini):
                 dicetak besar & di tengah agar mudah dipindai petugas saat Aktivasi
                 NUP / Pilih Unit. Di kop ukurannya 58px — terlalu kecil untuk dipindai
                 andal dari layar HP maupun hasil cetak. --}}
            <div class="src">
                <div class="lbl">Sumber Informasi</div>
                <div class="val">{{ $sumberInfo !== '—' ? $sumberInfo : '' }}</div>
            </div>
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
            @foreach ((array) $dok['syarat'] as $sy)
                @if (filled($sy))<li>{!! $tx($sy) !!}</li>@endif
            @endforeach
        </ol>
    </div>

    <div style="margin-top:10px;">{{ $dok['kota'] }}, {{ $tgl($tglSurat) }}</div>

    <div class="sign">
        <div class="c"><div class="line">{{ $nama ?: '' }}<br>Pembeli</div></div>
        {{-- Tanpa nomor telepon: kolom tanda tangan cukup nama sales + jabatan. --}}
        <div class="c"><div class="line">{{ $sales }}<br>Marketing</div></div>
        <div class="c"><div class="line">{{ $dok['label_perusahaan'] }}</div></div>
    </div>

    {{-- ════════════ HALAMAN 2 — QR CUSTOMER ════════════
         Dipisah ke halamannya sendiri supaya bisa dicetak besar: petugas memindainya
         dari kertas/HP saat Aktivasi NUP & Pilih Unit. Isi QR = KODE customer, bukan
         data pribadi (lihat CustomerQrService). Halaman ini hanya terbit bila QR-nya
         ada — kalau customer belum punya kode, halaman kosong tidak dicetak. --}}
    @if (! empty($qrSvg))
        <div style="page-break-before: always;"></div>
        <div class="qrpage">
            {!! $qrSvg !!}
            <div class="nama">{{ $nama ?: '—' }}</div>
            @if ($namaEvent)
                <div class="event">{{ $namaEvent }}</div>
            @endif
            {{-- No NUP menemani QR: petugas Pilih Unit sering perlu mencocokkannya
                 dengan daftar antrean, dan mengetik nomor lebih cepat daripada
                 memindai ulang bila pemindainya bermasalah. --}}
            @if ($reg->no_nup)
                <div class="nup">{{ $reg->no_nup }}</div>
            @endif
            <div class="ket">Barcode ini Digunakan Untuk Memudahkan Proses Pemilihan Unit.</div>
        </div>
    @endif

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
