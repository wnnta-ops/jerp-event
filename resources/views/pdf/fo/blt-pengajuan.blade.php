<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Permohonan Pengajuan BLT</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 11px; margin: 0; padding: 18px 22px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .head { display: flex; align-items: center; border-bottom: 1px solid #111; padding-bottom: 8px; margin-bottom: 4px; }
        .brand { width: 120px; font-weight: 800; color: #ea7a1d; font-size: 22px; }
        .head .co { text-align: center; flex: 1; }
        .head .co .ttl { font-weight: 800; font-size: 13px; letter-spacing: .3px; }
        .tgl { text-align: center; font-size: 11px; margin: 6px 0 8px; }
        table.f { width: 100%; border-collapse: collapse; }
        table.f th, table.f td { border: 1px solid #111; padding: 3px 5px; font-size: 10px; vertical-align: middle; }
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
    $labels = ['SALES' => 'SALES', 'KOORDINATOR' => 'KOORDINATOR', 'KANTOR' => 'KANTOR'];
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

@forelse ($groups as $type => $rows)
    @php
        $label = $labels[$type] ?? $type;
        $total = $rows->sum('nilai_blt');
        $totalPph = $rows->sum('pph_amount');
        $totalNett = $rows->sum('nett');
    @endphp
    <div class="page">
        <div class="head">
            <div class="brand">M</div>
            <div class="co">
                <div class="ttl">PERMOHONAN PENGAJUAN BLT {{ $label }} UNTUK PENJUALAN {{ $lokasiName ?: '—' }}</div>
            </div>
            <div style="width:120px"></div>
        </div>
        <div class="tgl">TANGGAL : {{ $tanggal->translatedFormat('d F Y') }}</div>

        <table class="f">
            <thead>
                <tr>
                    <th style="width:26px">NO</th>
                    <th>NAMA PEMBELI</th>
                    <th>{{ $label }}</th>
                    <th>AGENT</th>
                    <th>BLOK/KAV</th>
                    <th>CARA BAYAR</th>
                    <th>NILAI BLT</th>
                    <th>PPh</th>
                    <th>NETT</th>
                    <th>NOMOR REKENING</th>
                    <th>BANK</th>
                    <th>A/N</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $b)
                    @php
                        $a = $b->agent;
                        $unit = $b->kontrak?->unit;
                        $blok = $unit?->kode_unit ?: $unit?->no_unit ?: '—';
                        $rek = $b->rek_number ?: $a?->rekening_number;
                        $bank = $b->rek_bank ?: $a?->rekening_bank;
                        $an = $b->rek_holder ?: ($a?->rekening_holder ?: $b->nama_user);
                    @endphp
                    <tr>
                        <td class="c">{{ $i + 1 }}</td>
                        <td>{{ $b->kontrak?->customer?->full_name ?: '—' }}</td>
                        <td>{{ $b->nama_user ?: '—' }}</td>
                        <td class="c">{{ $a?->is_arebi ? 'AGENT AREBI' : 'AGENT NON AREBI' }}</td>
                        <td class="c">{{ $blok }}</td>
                        <td class="c">{{ $b->kontrak?->cara_bayar ?: '—' }}</td>
                        <td class="r">{{ $rp($b->nilai_blt) }}</td>
                        <td class="r">{{ $rp($b->pph_amount) }}</td>
                        <td class="r">{{ $rp($b->nett) }}</td>
                        <td>{{ $rek ?: '—' }}</td>
                        <td class="c">{{ $bank ?: '—' }}</td>
                        <td>{{ $an ?: '—' }}</td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td colspan="6" class="r">TOTAL</td>
                    <td class="r">{{ $rp($total) }}</td>
                    <td class="r">{{ $rp($totalPph) }}</td>
                    <td class="r">{{ $rp($totalNett) }}</td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>

        <div class="totbox">TOTAL {{ $rp($total) }}</div>
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
    </div>
@empty
    <div class="empty">Tidak ada data BLT untuk dicetak.</div>
@endforelse

    <div class="actions">
        <a href="#" class="btn" onclick="window.print();return false;">Cetak / Simpan PDF</a>
    </div>
</body>
</html>
