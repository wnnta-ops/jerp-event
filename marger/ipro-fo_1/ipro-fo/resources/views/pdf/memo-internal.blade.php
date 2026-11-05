{{-- Cetak Memo Internal Invoice Hutang (ApInvoiceController::memo) — Memo Internal + Bukti Permohonan
     Pembayaran dalam satu halaman A4 portrait (+ halaman lampiran Alokasi / Koreksi Budget bila ada), dengan informasi budget di tabel rincian:
       Perihal / "keperluan" = Uraian langkah Detil Tagihan; Uraian Pembayaran = Supplier — Keterangan
       baris Struktur Jurnal (selain akun hutang). Baris pengeluaran per baris jurnal; kolom budget
       digabung (rowspan) per anggaran (akun × cost center / "semua"). Angka budget = Master Budget
       bulan & tahun invoice, realisasi TANPA jurnal invoice ini (= sebelum pengajuan). --}}
@php
    $rp = fn ($v) => number_format((float) $v, 2, ',', '.');
    $rp0 = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $pct = fn ($a, $b) => $b > 0 ? number_format($a / $b * 100, 1, ',', '.').'%' : '—';
    $cb = fn (bool $on) => $on ? '☑' : '☐';
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Memo Internal {{ $no }}</title>
<style>
    @page { size: A4; margin: 8mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 9.5px; color: #111; margin: 0; line-height: 1.35; background: #fff; }
    .hal { position: relative; page-break-after: always; } .hal:last-child { page-break-after: auto; }
    .atas { display: grid; grid-template-columns: 150px 1fr 150px; align-items: start; }
    .logo { width: 52px; height: 52px; object-fit: contain; }
    .judul { text-align: center; } .judul h1 { font-size: 20px; margin: 2px 0; letter-spacing: .05em; } .judul .no { font-size: 12px; }
    .created { text-align: right; font-size: 8px; color: #666; }
    table { border-collapse: collapse; width: 99.7%; }
    .kepala { margin-top: 6px; } .kepala td { border: 1px solid #9ca3af; padding: 2px 6px; vertical-align: top; }
    .kepala .l { width: 82px; } .kepala .s { width: 8px; text-align: center; } .kepala .k { background: #fde7dc; color: #111; font-weight: bold; width: 105px; }
    .kepala .kv { background: #fde7dc; }
    .bayar { margin-top: 6px; border: 2px solid #111; border-radius: 8px; padding: 5px 8px; background: #fff7f2; display: grid; grid-template-columns: 1.4fr .8fr .9fr 1fr; gap: 4px 8px; }
    .bayar .h { color: #111; font-weight: bold; grid-column: 1 / -1; font-size: 11px; }
    .bayar b { display: block; }
    p { margin: 6px 0 4px; }
    .rinci { table-layout: fixed; }
    .rinci th, .rinci td { border: 1px solid #9ca3af; padding: 3px 4px; vertical-align: middle; overflow-wrap: anywhere; font-size: 8.8px; }
    .rinci th { font-size: 8px; text-transform: uppercase; }
    .rinci th.g { background: #f3f4f6; }
    /* Kepala kolom budget: teks hitam (tetap terbaca walau dicetak tanpa warna latar). */
    .rinci th.b1, .rinci th.b2, .rinci th.b3, .rinci th.b4, .rinci th.b5 { background: #f3f4f6; color: #111; }
    .rinci td.bud { text-align: center; font-weight: bold; }
    .rinci td.bud small { display: block; font-weight: normal; color: #6b7280; font-size: 7.5px; white-space: normal; }
    .rinci td.akun { background: #fde7dc; text-align: center; font-family: 'Courier New', monospace; }
    .rinci td.pos { font-size: 9px; color: #374151; text-align: center; }
    .r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } .c { text-align: center; }
    .minus { color: #111; }
    .bar { display: flex; height: 12px; border: 1px solid #111; border-radius: 3px; overflow: hidden; font-size: 8px; color: #111; font-weight: bold; }
    .bar span { min-width: 0; display: flex; align-items: center; justify-content: center; white-space: nowrap; overflow: hidden; }
    .rinci tr.tot td { font-weight: bold; background: #f3f4f6; }
    .terbilang { border: 1px solid #9ca3af; border-top: 0; padding: 3px 6px; background: #fff7ed; } .terbilang b { color: #111; }
    .ttd { margin-top: 10px; table-layout: fixed; } .ttd td { text-align: center; vertical-align: top; padding: 0 4px; }
    .ttd .cap { font-size: 9px; height: 14px; } .ttd .cap.o { color: #111; font-weight: bold; }
    .ttd .sp { height: 46px; } .ttd .nm { border-top: 1px solid #555; padding-top: 2px; font-weight: bold; font-size: 8px; min-height: 13px; }
    .ttd .jb { color: #111; font-weight: bold; font-size: 7.5px; }
    .ttd td { padding: 0 2px; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #e5e7eb; } .hal { background: #fff; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 9mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
</style>
</head>
<body>
@php $h = ['grup' => $grup]; @endphp
<div class="hal">
    <div class="atas">
        <img class="logo" src="/images/mup-logo.jpeg" alt="MUP">
        <div class="judul"><h1>MEMO INTERNAL</h1><div class="no">No : {{ $no }}</div></div>
        <div class="created">Created By Java Core System<br>({{ $dicetak }})</div>
    </div>

    <table class="kepala">
        <tr><td class="l">Kepada Yth</td><td class="s">:</td><td>RS</td><td class="k">Departemen</td><td class="s kv">:</td><td class="kv">{{ $dept }}</td></tr>
        <tr><td class="l">Tembusan</td><td class="s">:</td><td>BA, MW</td><td class="k">Dibayarkan Kepada</td><td class="s kv">:</td><td class="kv">{{ $supplier }}</td></tr>
        <tr><td class="l">Dari</td><td class="s">:</td><td>{{ $dari }}</td><td class="k">No. Voucher</td><td class="s kv">:</td><td class="kv">{{ $no }}</td></tr>
        <tr><td class="l">Hari / Tanggal</td><td class="s">:</td><td>{{ $tanggal }}</td><td class="k">No. Cek / BG</td><td class="s kv">:</td><td class="kv">-</td></tr>
        <tr><td class="l">Perihal</td><td class="s">:</td><td colspan="4">{{ $perihal }}</td></tr>
    </table>

    <div class="bayar">
        <div class="h">INFORMASI PEMBAYARAN</div>
        <div><b>Dibayar Via :</b>{{ $cb(! $bank['ada']) }} KAS<br>{{ $cb($bank['ada']) }} Bank{{ $bank['nama'] ? ' : '.$bank['nama'] : '' }}<br>No. Rek / a.n. : {{ $bank['rek'] }} / {{ $bank['an'] }}</div>
        <div><b>Type Pengeluaran :</b>{{ $cb($pekerjaan) }} Pekerjaan<br>{{ $cb(! $pekerjaan) }} Material</div>
        <div><b>Cara Pembayaran Bank :</b>{{ $cb($bank['ada']) }} Transfer<br>{{ $cb(! $bank['ada']) }} Tunai</div>
        {{-- Budget: Sesuai Budget bila invoice ini tidak membawa alokasi / koreksi (lihat halaman lampiran). --}}
        @php $jenisUbah = collect($lampiran ?? [])->pluck('jenis'); @endphp
        <div><b>Budget :</b>{{ $cb($jenisUbah->isEmpty()) }} Sesuai Budget<br>{{ $cb($jenisUbah->contains('Alokasi')) }} Alokasi Budget<br>{{ $cb($jenisUbah->contains('Koreksi')) }} Koreksi Budget</div>
    </div>

    <p>Dengan hormat, sehubungan dengan keperluan <b>{{ $perihal }}</b>, maka kami mengajukan permohonan pembayaran sebesar <b>Rp. {{ $rp($total) }}</b> dengan rincian sebagai berikut :</p>

    @php $bln = $bulanLabel; @endphp
    <table class="rinci">
        <colgroup>
            <col style="width:4%"><col style="width:11%"><col style="width:24%"><col style="width:13%">
            <col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:12%">
        </colgroup>
        <thead>
            <tr>
                <th class="g">No.</th><th class="g">No. Account</th><th class="g">Uraian Pembayaran</th><th class="g">Jumlah (Rp)</th>
                <th class="b1">Budget {{ $bln }}</th><th class="b2">Realisasi {{ $bln }}</th>
                <th class="b4">Sisa budget {{ $bln }}</th><th class="b5">Sisa budget {{ $tahun }}</th>
            </tr>
        </thead>
        <tbody>
            @php $urut = 0; @endphp
            @foreach ($h['grup'] as $g)
                @foreach ($g['baris'] as $i => $b)
                    <tr>
                        <td class="c">{{ ++$urut }}</td>
                        <td class="akun">{{ $b['kode'] }}</td>
                        <td>{{ $b['uraian'] }}</td>
                        <td class="r">{{ $rp($b['jumlah']) }}</td>
                        @if ($i === 0)
                            @if ($g['budget'])
                                @php $x = $g['budget']; @endphp
                                <td class="bud" rowspan="{{ count($g['baris']) }}">{{ $rp0($x['anggaran']) }}</td>
                                <td class="bud" rowspan="{{ count($g['baris']) }}">{{ $rp0($x['terpakai']) }}<small>{{ $pct($x['terpakai'], $x['anggaran']) }} dari budget {{ $bln }}</small></td>
                                <td class="bud {{ $x['sisa'] < 0 ? 'minus' : '' }}" rowspan="{{ count($g['baris']) }}">{{ $rp0($x['sisa']) }}<small>{{ $pct($x['sisa'], $x['anggaran']) }} dari budget {{ $bln }}</small></td>
                                <td class="bud {{ $x['sisa_tahun'] < 0 ? 'minus' : '' }}" rowspan="{{ count($g['baris']) }}">{{ $rp0($x['sisa_tahun']) }}<small>{{ $pct($x['sisa_tahun'], $x['anggaran_tahun']) }} dari {{ $rp0($x['anggaran_tahun']) }}</small></td>
                            @else
                                <td class="pos" colspan="4" rowspan="{{ count($g['baris']) }}">Non budget — akun tanpa anggaran</td>
                            @endif
                        @endif
                    </tr>
                @endforeach
                @if ($g['budget'])
                    @php $x = $g['budget']; $a = max($x['anggaran'], $x['terpakai'] + $x['pengajuan'], 1);
                        $w1 = min(100, $x['terpakai'] / $a * 100); $w2 = min(100 - $w1, $x['pengajuan'] / $a * 100); $w3 = max(0, 100 - $w1 - $w2); @endphp
                    <tr><td colspan="4" style="border-top:0;border-bottom:0"></td><td colspan="4" style="padding:2px 4px">
                        <div class="bar">
                            @if ($w1 > 0)<span style="width:{{ $w1 }}%;background:#d1d5db">{{ $w1 >= 18 ? 'Terpakai '.$pct($x['terpakai'], $x['anggaran']) : '' }}</span>@endif
                            @if ($w2 > 0)<span style="width:{{ $w2 }}%;background:#e5e7eb;border-left:1px solid #111">{{ $w2 >= 22 ? 'Pengajuan ini '.$pct($x['pengajuan'], $x['anggaran']) : '' }}</span>@endif
                            @if ($w3 > 0)<span style="width:{{ $w3 }}%;background:#fff;border-left:1px solid #111">{{ $w3 >= 12 ? 'Sisa '.$pct($x['sisa'], $x['anggaran']) : '' }}</span>@endif
                        </div>
                    </td></tr>
                @endif
            @endforeach
            <tr><td colspan="3" class="r">PPN {{ $ppnPct }}%</td><td class="r">{{ $rp($ppn) }}</td><td colspan="4" rowspan="3" class="pos" style="text-align:left;vertical-align:top;color:#111">
                <b>Terbilang :</b> {{ $terbilang }}</td></tr>
            <tr><td colspan="3" class="r">PPh</td><td class="r">{{ $pph > 0 ? '- '.$rp($pph) : $rp(0) }}</td></tr>
            <tr class="tot"><td colspan="3" class="r">TOTAL</td><td class="r">{{ $rp($total) }}</td></tr>
        </tbody>
    </table>
    <table class="ttd">
        <colgroup>@for ($k = 0; $k < 8; $k++)<col style="width:12.5%">@endfor</colgroup>
        <tr>
            <td class="cap">Hormat Kami,</td><td class="cap o">Diverifikasi,</td><td class="cap" colspan="2">Menyetujui,</td>
            <td class="cap" colspan="2">Mengetahui,</td><td class="cap o">Dibayarkan,</td><td class="cap o">Diterima,</td>
        </tr>
        <tr>@for ($k = 0; $k < 8; $k++)<td class="sp"></td>@endfor</tr>
        <tr>
            <td><div class="nm">{{ $dari }}</div><div class="jb">Dept. Head</div></td>
            <td><div class="nm">(………………)</div><div class="jb">Manager Accounting &amp; Finance</div></td>
            <td><div class="nm">Rizki Setyawardana</div><div class="jb">General Manager</div></td>
            <td><div class="nm">Rachmat Suhendra</div><div class="jb">Direktur</div></td>
            <td><div class="nm">Moch Winarto</div><div class="jb">&nbsp;</div></td>
            <td><div class="nm">Boy Arifin</div><div class="jb">&nbsp;</div></td>
            <td><div class="nm">(……………)</div><div class="jb">General Cashier</div></td>
            <td><div class="nm">(……………)</div><div class="jb">Penerima</div></td>
        </tr>
    </table>
    <div class="noprint" style="margin-top:12px">
        <button onclick="window.print()" style="padding:6px 14px">Cetak</button>
        <a href="{{ $kembali }}" style="margin-left:10px;padding:6px 14px;border:1px solid #ccc;text-decoration:none;color:#333;border-radius:4px">&larr; Kembali ke Invoice</a>
    </div>
</div>

@if ($lampiran)
{{-- LAMPIRAN — halaman terpisah: alokasi / koreksi budget yang diajukan untuk invoice ini. --}}
<div class="hal">
    <div class="atas">
        <img class="logo" src="/images/mup-logo.jpeg" alt="MUP">
        <div class="judul"><h1 style="font-size:16px">LAMPIRAN</h1><div class="no">Informasi Alokasi / Koreksi Budget</div><div class="no">Memo Internal No : {{ $no }}</div></div>
        <div class="created">Created By Java Core System<br>({{ $dicetak }})</div>
    </div>
    <table class="rinci" style="margin-top:10px">
        <colgroup><col style="width:4%"><col style="width:13%"><col style="width:9%"><col style="width:24%"><col style="width:13%"><col style="width:11%"><col style="width:12%"><col style="width:14%"></colgroup>
        <thead><tr>
            <th class="g">No.</th><th class="g">No. Dokumen</th><th class="g">Jenis</th><th class="g">Budget</th><th class="g">Periode</th><th class="g">Nilai (Rp)</th><th class="g">Status</th><th class="g">Diputuskan</th>
        </tr></thead>
        <tbody>
            @foreach ($lampiran as $i => $l)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td style="font-family:'Courier New',monospace">{{ $l['doc_no'] }}</td>
                    <td class="c">{{ $l['jenis'] }}</td>
                    <td>@if ($l['dari'])<b>Dari:</b> {{ $l['dari'] }}<br><b>Ke:</b> {{ $l['ke'] }}@else{{ $l['ke'] }}@endif</td>
                    <td class="c">{{ $l['periode'] }}</td>
                    <td class="r">{{ $rp($l['nilai']) }}</td>
                    <td class="c" style="font-weight:bold">{{ $l['status'] }}</td>
                    <td>{{ $l['oleh'] ?? '—' }}@if ($l['pada'])<br><span style="color:#6b7280">{{ $l['pada'] }}</span>@endif</td>
                </tr>
                @if ($l['catatan'])<tr><td></td><td colspan="7" style="color:#4b5563">Keterangan: {{ $l['catatan'] }}</td></tr>@endif
            @endforeach
        </tbody>
    </table>
    <p style="color:#6b7280;font-size:8.5px">Alokasi = pemindahan anggaran dari budget lain; Koreksi = tambahan anggaran. Keduanya disetujui Finance (Approval Inbox) dan berlaku untuk bulan invoice.</p>
</div>
@endif
<script>window.onload=function(){setTimeout(function(){window.print()},300)}</script>
</body>
</html>
