{{-- Satu halaman Lampiran Perubahan Budget — dipakai lembar mandiri (lampiran-perubahan-budget) & sesudah
     halaman 1 Memo Internal (memo-internal). Kelas berawalan lpb- supaya tidak bentrok dengan gaya memo.
     Asal: BudgetLampiranController — satu halaman A4 per Alokasi / Koreksi Budget:
     budget yang berubah, tabel per bulan sebelum → perubahan → sesudah → realisasi → sisa, alasan, asal, pengaju, approval.
     Data dari LampiranPerubahanBudget. --}}
@php
    $rp = fn ($v) => number_format((float) $v, 2, ',', '.');
    $tanda = fn ($v) => ((float) $v > 0 ? '+' : '').number_format((float) $v, 2, ',', '.');
    $statusLangkah = ['approved' => 'Disetujui', 'rejected' => 'Ditolak', 'pending' => 'Menunggu', 'waiting' => 'Menunggu', 'skipped' => 'Dilewati'];
@endphp
@once
<style>
    .lpb { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #111; line-height: 1.4; }
    .lpb table { border-collapse: collapse; width: 100%; }
    .lpb .lpb-atas { display: grid; grid-template-columns: 150px 1fr 150px; align-items: start; }
    .lpb .lpb-logo { width: 52px; height: 52px; object-fit: contain; }
    .lpb .lpb-judul { text-align: center; } .lpb .lpb-judul h1 { font-size: 17px; margin: 2px 0; letter-spacing: .05em; } .lpb .lpb-judul .lpb-no { font-size: 11px; }
    .lpb .lpb-created { text-align: right; font-size: 8px; color: #666; }
    .lpb .lpb-kepala { margin-top: 10px; } .lpb .lpb-kepala td { border: 1px solid #9ca3af; padding: 3px 6px; vertical-align: top; }
    .lpb .lpb-kepala .lpb-l { width: 110px; background: #fde7dc; font-weight: bold; }
    .lpb h2 { font-size: 11px; margin: 12px 0 4px; text-transform: uppercase; }
    .lpb .lpb-info { color: #374151; margin-bottom: 4px; }
    .lpb .lpb-rinci th, .lpb .lpb-rinci td { border: 1px solid #9ca3af; padding: 3px 6px; }
    .lpb .lpb-rinci th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
    .lpb .lpb-rinci tr.lpb-tot td { font-weight: bold; background: #f3f4f6; }
    .lpb .lpb-rinci tr.lpb-batas td { border-top: 2px solid #111; }
    .lpb .lpb-r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } .lpb .lpb-c { text-align: center; }
    .lpb .lpb-min { color: #b91c1c; } .lpb .lpb-plus { color: #047857; }
    .lpb .lpb-alasan { border: 1px solid #9ca3af; padding: 5px 8px; min-height: 34px; white-space: pre-line; background: #fff7ed; }
    .lpb .lpb-ttd { margin-top: 16px; table-layout: fixed; page-break-inside: avoid; }
    .lpb .lpb-ttd td { text-align: center; vertical-align: top; padding: 0 6px; }
    .lpb .lpb-ttd .lpb-ruang { height: 60px; display: flex; align-items: center; justify-content: center; }
    .lpb .lpb-ttd img { max-height: 54px; max-width: 100%; }
    .lpb .lpb-ttd .lpb-garis { border-top: 1px solid #555; padding-top: 2px; font-weight: bold; min-height: 15px; }
    .lpb .lpb-ttd .lpb-wkt { color: #6b7280; font-size: 8px; }
    .lpb .lpb-cap-ok, .lpb .lpb-cap-tolak { display: inline-block; transform: rotate(-8deg); border: 2px solid #047857; color: #047857; font-weight: bold; padding: 1px 6px; font-size: 9px; }
    .lpb .lpb-cap-tolak { border-color: #b91c1c; color: #b91c1c; }
</style>
@endonce
<div class="hal lpb">
    <div class="lpb-atas">
        <img class="lpb-logo" src="/images/mup-logo.jpeg" alt="MUP">
        <div class="lpb-judul"><h1>LAMPIRAN PERUBAHAN BUDGET</h1><div class="lpb-no">{{ $jenis }} No : {{ $no }}</div></div>
        <div class="lpb-created">Created By Java Core System<br>({{ $dicetak }})</div>
    </div>

    <table class="lpb-kepala">
        <tr><td class="lpb-l">Jenis</td><td>{{ $jenis }}</td><td class="lpb-l">Tanggal</td><td>{{ $tanggal ?? '—' }}</td></tr>
        <tr><td class="lpb-l">Asal pengajuan</td><td>{{ $asal }}</td><td class="lpb-l">Status</td><td><b>{{ $status }}</b></td></tr>
        <tr><td class="lpb-l">Diajukan oleh</td><td>{{ $pengaju ?? '—' }}</td><td class="lpb-l">Total perubahan</td><td class="lpb-r" style="text-align:left"><b>Rp {{ $rp($total) }}</b></td></tr>
    </table>

    <h2>Alasan / Keterangan</h2>
    <div class="lpb-alasan">{{ $alasan ?: '—' }}</div>

    {{-- ALOKASI: ringkasan per budget — awal, nilai alokasi (Dari −, Ke +), setelah alokasi; garis tebal memisahkan Dari & Ke. --}}
    @if ($jenis === 'Alokasi Budget' && count($budgets))
        <h2>Ringkasan Alokasi</h2>
        <table class="lpb-rinci">
            <thead><tr><th style="width:9%">Arah</th><th>Budget</th><th style="width:17%">Budget awal (Rp)</th><th style="width:17%">Nilai alokasi (Rp)</th><th style="width:17%">Setelah alokasi (Rp)</th></tr></thead>
            <tbody>
                @foreach ($budgets as $i => $b)
                    @php
                        $awal = array_sum(array_column($b['bulan'], 'sebelum'));
                        $ubah = array_sum(array_column($b['bulan'], 'ubah'));
                    @endphp
                    <tr @if ($i > 0) class="lpb-batas" @endif>
                        <td class="lpb-c"><b>{{ $ubah < 0 ? 'Dari' : 'Ke' }}</b></td>
                        <td>{{ $b['akun'] }}<br><span style="color:#6b7280">{{ $b['cost_center'] }} · {{ collect($b['bulan'])->pluck('bulan')->implode(', ') }} {{ $b['year'] }}</span></td>
                        <td class="lpb-r">{{ $rp($awal) }}</td>
                        <td class="lpb-r {{ $ubah < 0 ? 'lpb-min' : 'lpb-plus' }}"><b>{{ $tanda($ubah) }}</b></td>
                        <td class="lpb-r"><b>{{ $rp($awal + $ubah) }}</b></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @foreach ($budgets as $b)
        <h2>{{ $b['peran'] }}</h2>
        <div class="lpb-info"><b>{{ $b['doc_no'] }}</b> · Tahun {{ $b['year'] }} · Akun {{ $b['akun'] }} · {{ $b['cost_center'] }}</div>
        <table class="lpb-rinci">
            <thead><tr><th style="width:14%">Bulan</th><th>Anggaran sebelum (Rp)</th><th>Perubahan (Rp)</th><th>Anggaran sesudah (Rp)</th><th>Realisasi (Rp)</th><th>Sisa (Rp)</th></tr></thead>
            <tbody>
                @foreach ($b['bulan'] as $m)
                    <tr>
                        <td class="lpb-c">{{ $m['bulan'] }} {{ $b['year'] }}</td>
                        <td class="lpb-r">{{ $rp($m['sebelum']) }}</td>
                        <td class="lpb-r {{ $m['ubah'] < 0 ? 'lpb-min' : 'lpb-plus' }}">{{ $tanda($m['ubah']) }}</td>
                        <td class="lpb-r">{{ $rp($m['sesudah']) }}</td>
                        <td class="lpb-r">{{ $rp($m['realisasi'] ?? 0) }}</td>
                        <td class="lpb-r {{ ($m['sisa'] ?? 0) < 0 ? 'lpb-min' : '' }}">{{ $rp($m['sisa'] ?? 0) }}</td>
                    </tr>
                @endforeach
                <tr class="lpb-tot">
                    <td class="lpb-c">Setahun</td>
                    <td class="lpb-r">{{ $rp($b['tahun']['sebelum']) }}</td>
                    <td class="lpb-r">{{ $tanda($b['tahun']['ubah']) }}</td>
                    <td class="lpb-r">{{ $rp($b['tahun']['sesudah']) }}</td>
                    <td class="lpb-r">{{ $rp($b['tahun']['realisasi'] ?? 0) }}</td>
                    <td class="lpb-r">{{ $rp($b['tahun']['sisa'] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <h2>Riwayat Approval</h2>
    <table class="lpb-rinci">
        <thead><tr><th style="width:22%">Tahap</th><th style="width:14%">Status</th><th style="width:22%">Oleh</th><th style="width:16%">Tanggal</th><th>Catatan</th></tr></thead>
        <tbody>
            @forelse ($langkah as $l)
                <tr>
                    <td>{{ $l['label'] }}</td>
                    <td class="lpb-c"><b>{{ $statusLangkah[$l['status']] ?? ucfirst((string) $l['status']) }}</b></td>
                    <td>{{ $l['oleh'] ?? '—' }}</td>
                    <td class="lpb-c">{{ $l['tanggal'] ?? '—' }}</td>
                    <td>{{ $l['catatan'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="lpb-c" style="color:#6b7280">Belum ada keputusan approval.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{-- TTD: pengaju + tiap tahap yang sudah memutuskan. Tahap yang belum bertindak tidak ditampilkan. --}}
    @php $diputus = array_values(array_filter($langkah, fn ($l) => in_array($l['status'], ['approved', 'rejected'], true))); @endphp
    <table class="lpb-ttd">
        <tr>
            <td>Diajukan oleh,</td>
            @foreach ($diputus as $l)<td>{{ $l['status'] === 'rejected' ? 'Ditolak,' : 'Disetujui,' }}</td>@endforeach
        </tr>
        <tr>
            <td>
                <div class="lpb-ruang">@if ($pengaju_ttd)<img src="{{ $pengaju_ttd }}" alt="TTD {{ $pengaju }}">@endif</div>
                <div class="lpb-garis">{{ $pengaju ?? '(………………)' }}</div>
                <div class="lpb-wkt">Pengaju</div>
            </td>
            @foreach ($diputus as $l)
                <td>
                    <div class="lpb-ruang">
                        @if ($l['status'] === 'rejected')<span class="lpb-cap-tolak">DITOLAK</span>
                        @elseif ($l['ttd'] ?? null)<img src="{{ $l['ttd'] }}" alt="TTD {{ $l['oleh'] }}">
                        @else<span class="lpb-cap-ok">DISETUJUI</span>@endif
                    </div>
                    <div class="lpb-garis">{{ $l['oleh'] ?? '(………………)' }}</div>
                    <div class="lpb-wkt">{{ $l['label'] }} · {{ $l['tanggal'] ?? '' }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <p style="color:#6b7280;font-size:8.5px;margin-top:8px">
        @if ($status_kode === 'approved')Perubahan sudah masuk Master Budget — "sesudah" = anggaran saat ini.
        @elseif ($status_kode === 'rejected')Pengajuan ditolak — Master Budget tidak berubah.
        @else Belum disetujui — "sebelum" = anggaran saat ini; perubahan baru berlaku setelah disetujui.@endif
    </p>
</div>
