{{-- Lampiran Perubahan Budget (BudgetLampiranController) — satu halaman A4 per Alokasi / Koreksi Budget:
     budget yang berubah, tabel per bulan sebelum → perubahan → sesudah, alasan, asal, pengaju, approval.
     Data dari LampiranPerubahanBudget. --}}
@php
    $rp = fn ($v) => number_format((float) $v, 2, ',', '.');
    $tanda = fn ($v) => ((float) $v > 0 ? '+' : '').number_format((float) $v, 2, ',', '.');
    $statusLangkah = ['approved' => 'Disetujui', 'rejected' => 'Ditolak', 'pending' => 'Menunggu', 'waiting' => 'Menunggu', 'skipped' => 'Dilewati'];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Lampiran {{ $jenis }} {{ $no }}</title>
<style>
    @page { size: A4; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #111; margin: 0; line-height: 1.4; background: #fff; }
    .atas { display: grid; grid-template-columns: 150px 1fr 150px; align-items: start; }
    .logo { width: 52px; height: 52px; object-fit: contain; }
    .judul { text-align: center; } .judul h1 { font-size: 17px; margin: 2px 0; letter-spacing: .05em; } .judul .no { font-size: 11px; }
    .created { text-align: right; font-size: 8px; color: #666; }
    table { border-collapse: collapse; width: 100%; }
    .kepala { margin-top: 10px; } .kepala td { border: 1px solid #9ca3af; padding: 3px 6px; vertical-align: top; }
    .kepala .l { width: 110px; background: #fde7dc; font-weight: bold; }
    h2 { font-size: 11px; margin: 12px 0 4px; text-transform: uppercase; }
    .info { color: #374151; margin-bottom: 4px; }
    .rinci th, .rinci td { border: 1px solid #9ca3af; padding: 3px 6px; }
    .rinci th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
    .rinci tr.tot td { font-weight: bold; background: #f3f4f6; }
    .r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } .c { text-align: center; }
    .min { color: #b91c1c; } .plus { color: #047857; }
    .alasan { border: 1px solid #9ca3af; padding: 5px 8px; min-height: 34px; white-space: pre-line; background: #fff7ed; }
    .noprint { margin: 12px auto; width: 210mm; text-align: right; }
    .noprint button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #9ca3af; background: #fff; cursor: pointer; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #e5e7eb; } .hal { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto 12px; padding: 10mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
</style>
</head>
<body>
<div class="noprint"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
<div class="hal">
    <div class="atas">
        <img class="logo" src="/images/mup-logo.jpeg" alt="MUP">
        <div class="judul"><h1>LAMPIRAN PERUBAHAN BUDGET</h1><div class="no">{{ $jenis }} No : {{ $no }}</div></div>
        <div class="created">Created By Java Core System<br>({{ $dicetak }})</div>
    </div>

    <table class="kepala">
        <tr><td class="l">Jenis</td><td>{{ $jenis }}</td><td class="l">Tanggal</td><td>{{ $tanggal ?? '—' }}</td></tr>
        <tr><td class="l">Asal pengajuan</td><td>{{ $asal }}</td><td class="l">Status</td><td><b>{{ $status }}</b></td></tr>
        <tr><td class="l">Diajukan oleh</td><td>{{ $pengaju ?? '—' }}</td><td class="l">Total perubahan</td><td class="r" style="text-align:left"><b>Rp {{ $rp($total) }}</b></td></tr>
    </table>

    <h2>Alasan / Keterangan</h2>
    <div class="alasan">{{ $alasan ?: '—' }}</div>

    @foreach ($budgets as $b)
        <h2>{{ $b['peran'] }}</h2>
        <div class="info"><b>{{ $b['doc_no'] }}</b> · Tahun {{ $b['year'] }} · Akun {{ $b['akun'] }} · {{ $b['cost_center'] }}</div>
        <table class="rinci">
            <thead><tr><th style="width:18%">Bulan</th><th>Anggaran sebelum (Rp)</th><th>Perubahan (Rp)</th><th>Anggaran sesudah (Rp)</th></tr></thead>
            <tbody>
                @foreach ($b['bulan'] as $m)
                    <tr>
                        <td class="c">{{ $m['bulan'] }} {{ $b['year'] }}</td>
                        <td class="r">{{ $rp($m['sebelum']) }}</td>
                        <td class="r {{ $m['ubah'] < 0 ? 'min' : 'plus' }}">{{ $tanda($m['ubah']) }}</td>
                        <td class="r">{{ $rp($m['sesudah']) }}</td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td class="c">Setahun</td>
                    <td class="r">{{ $rp($b['tahun']['sebelum']) }}</td>
                    <td class="r">{{ $tanda($b['tahun']['ubah']) }}</td>
                    <td class="r">{{ $rp($b['tahun']['sesudah']) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <h2>Riwayat Approval</h2>
    <table class="rinci">
        <thead><tr><th style="width:22%">Tahap</th><th style="width:14%">Status</th><th style="width:22%">Oleh</th><th style="width:16%">Tanggal</th><th>Catatan</th></tr></thead>
        <tbody>
            @forelse ($langkah as $l)
                <tr>
                    <td>{{ $l['label'] }}</td>
                    <td class="c"><b>{{ $statusLangkah[$l['status']] ?? ucfirst((string) $l['status']) }}</b></td>
                    <td>{{ $l['oleh'] ?? '—' }}</td>
                    <td class="c">{{ $l['tanggal'] ?? '—' }}</td>
                    <td>{{ $l['catatan'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="c" style="color:#6b7280">Belum ada keputusan approval.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p style="color:#6b7280;font-size:8.5px;margin-top:8px">
        @if ($status_kode === 'approved')Perubahan sudah masuk Master Budget — "sesudah" = anggaran saat ini.
        @elseif ($status_kode === 'rejected')Pengajuan ditolak — Master Budget tidak berubah.
        @else Belum disetujui — "sebelum" = anggaran saat ini; perubahan baru berlaku setelah disetujui.@endif
    </p>
</div>
</body>
</html>
