<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pengajuan Komisi</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 11px; margin: 0; padding: 18px 22px; }
        .head { display: flex; align-items: center; border-bottom: 1px solid #111; padding-bottom: 8px; margin-bottom: 4px; }
        .brand { width: 230px; font-weight: 800; color: #ea7a1d; font-size: 22px; }
        .brand img { width: 56px; height: 56px; object-fit: contain; display: block; }
        .cap { width: 230px; font-size: 10px; text-align: right; white-space: nowrap; align-self: flex-start; }
        .head .co { text-align: center; flex: 1; }
        .head .co .ttl { font-weight: 800; font-size: 13px; letter-spacing: .3px; }
        .tgl { text-align: center; font-size: 11px; margin: 6px 0 8px; }
        table.f { width: 100%; border-collapse: collapse; }
        table.f th, table.f td { border: 1px solid #111; padding: 3px 4px; font-size: 9px; vertical-align: middle; }
        table.f th { background: #f1f1f1; text-align: center; font-weight: 700; }
        td.r { text-align: right; }
        td.c { text-align: center; }
        tr.tot td { font-weight: 800; }
        .sign { display: flex; justify-content: space-between; margin-top: 36px; font-size: 11px; }
        .sign .c { text-align: center; }
        .sign .names { display: flex; justify-content: space-around; }
        .sign .line { flex: 1; margin-top: 48px; font-weight: 700; }
        .totbox { float: right; margin-top: 14px; border: 1px solid #111; padding: 6px 12px; font-weight: 800; font-size: 12px; }
        .actions { margin-top: 16px; }
        @media print { .actions { display: none; } body { padding: 8px; } }
        .btn { display: inline-block; padding: 8px 16px; background: #2563eb; color: #fff; border-radius: 8px; text-decoration: none; font-size: 13px; }
        .empty { padding: 40px; text-align: center; color: #777; }
    </style>
</head>
<body>
@php
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    // Logo MUP = logo kop Memo Internal BO (mup-logo.jpeg). Di-embed base64 supaya ikut
    // tercetak di PDF (Browsershot tak selalu bisa memuat URL lokal).
    $logoPath = public_path('images/mup-logo.jpeg');
    $logoMup = is_file($logoPath) ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
@endphp

@if ($rows->isEmpty())
    <div class="empty">Tidak ada data komisi untuk dicetak.</div>
@else
    <div class="head">
        <div class="brand">@if ($logoMup)<img src="{{ $logoMup }}" alt="PT Mitra Usaha Propertindo">@else M @endif</div>
        <div class="co">
            <div class="ttl">PENGAJUAN KOMISI PENJUALAN {{ $lokasiName ?: '—' }}</div>
        </div>
        <div class="cap">Created By Java Core System ({{ now()->format('d-m-Y H.i.s') }})</div>
    </div>
    <div class="tgl">TANGGAL : {{ $tanggal->translatedFormat('d F Y') }}@if ($noPengajuan) &nbsp;·&nbsp; No. Pengajuan : {{ $noPengajuan }}@endif</div>

    <table class="f">
        <thead>
            <tr>
                <th style="width:22px">NO</th>
                <th>NAMA PEMBELI</th>
                <th>KANTOR</th>
                <th>AGENT</th>
                <th>BLOK/KAV</th>
                <th>CARA BAYAR</th>
                <th>HARGA NETT</th>
                <th>DPP</th>
                <th>PRESENTASE KOMISI</th>
                <th>JUMLAH KOMISI</th>
                <th>POTONGAN PPH</th>
                <th>TOTAL KOMISI NET</th>
                <th>NOMOR REKENING</th>
                <th>BANK</th>
                <th>A/N</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $k)
                @php
                    $a = $k->agent; $unit = $k->kontrak?->unit;
                    $p = $a?->penerimaKomisi(); // rekening KANTOR agent, bukan sales perorangan
                    $rek = $k->rek_number ?: $p?->rekening_number;
                    $bank = $k->rek_bank ?: $p?->rekening_bank;
                    $an = $k->rek_holder ?: ($p?->rekening_holder ?: $p?->name);
                @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $k->kontrak?->customer?->full_name ?: '—' }}</td>
                    {{-- Kantor agent, ditelusuri lewat rantai atasan — lihat Agent::namaKantor(). --}}
                    <td>{{ $a?->namaKantor() ?: '—' }}</td>
                    <td class="c">{{ $a?->is_arebi ? 'AGENT AREBI' : 'AGENT NON AREBI' }}</td>
                    <td class="c">{{ $unit?->kode_unit ?: $unit?->no_unit ?: '—' }}</td>
                    <td class="c">{{ $k->kontrak?->cara_bayar ?: '—' }}</td>
                    <td class="r">{{ $rp($k->kontrak?->total_kontrak) }}</td>
                    <td class="r">{{ $rp($k->dpp) }}</td>
                    <td class="c">{{ number_format((float) $k->persen_komisi, 2, ',', '.') }}</td>
                    <td class="r">{{ $rp($k->amount) }}</td>
                    <td class="r">{{ $rp($k->pph_amount) }}</td>
                    <td class="r">{{ $rp($k->amount_net) }}</td>
                    <td>{{ $rek ?: '—' }}</td>
                    <td class="c">{{ $bank ?: '—' }}</td>
                    <td>{{ $an ?: '—' }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="9" class="r">TOTAL</td>
                <td class="r">{{ $rp($rows->sum('amount')) }}</td>
                <td class="r">{{ $rp($rows->sum('pph_amount')) }}</td>
                <td class="r">{{ $rp($rows->sum('amount_net')) }}</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>

    <div class="totbox">TOTAL KOMISI {{ $rp($rows->sum('amount')) }}</div>
    <div style="clear:both"></div>
    <div class="sign">
        @foreach ($ttd as $peran => $nama)
            {{-- Kolom melebar sesuai jumlah penanda tangannya; kosong = ruang untuk ditulis tangan. --}}
            <div class="c" style="flex: {{ max(count($nama), 1) }}">{{ $peran }}
                <div class="names">
                    @forelse ($nama as $n)
                        <div class="line">{{ $n }}</div>
                    @empty
                        <div class="line">&nbsp;</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
@endif

    <div class="actions">
        <a href="#" class="btn" onclick="window.print();return false;">Cetak / Simpan PDF</a>
    </div>
</body>
</html>
