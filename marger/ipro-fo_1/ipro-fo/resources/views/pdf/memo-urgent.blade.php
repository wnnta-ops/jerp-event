{{-- Lembar Memo Urgent — format Memo Internal (logo, kepala surat, rincian, TTD) dengan stempel URGENT.
     Dipanggil ApprovalInboxController::cetak; ?preview=1 dipakai iframe di halaman detail (tanpa dialog cetak).
       Kepada Yth : approver tahap yang sedang menunggu (tahap berikutnya).
       Tembusan   : approver tahap TERAKHIR chain Memo Urgent.
       TTD        : pengaju (Hormat Kami) + tiap tahap chain; terisi hanya untuk tahap yang SUDAH menyetujui.
                    Paling kanan: "Dibayarkan oleh" Finance — bukan tahap approval; terisi setelah memo dibayar. --}}
@php
    $e = fn ($v) => e((string) ($v ?? ''));
    $rp = fn ($v) => $v === null ? '—' : 'Rp '.number_format((float) $v, 0, ',', '.');
    $rp2 = fn ($v) => number_format((float) $v, 2, ',', '.');
    $hari = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('l, d F Y') : '—';
    $jam = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : null;
    $statusLabel = ['approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'][$status] ?? 'Dalam proses approval';

    $tahap = array_values($stages);
    // Nama orang: yang sudah bertindak, atau user yang ditunjuk chain (approver_type user). Tahap berbasis
    // PERAN (mis. Owner) tidak ditulis nama perannya — kotak TTD dibiarkan kosong.
    $orang = fn (?array $s) => $s ? trim((string) ($s['by'] ?? (($s['approver_type'] ?? null) === 'user' ? $s['role'] : ''))) : '';
    $sebut = fn (?array $s) => $s ? (($o = $orang($s)) !== '' && $o !== $s['label'] ? $o.' ('.$s['label'].')' : $s['label']) : '—';
    $berikut = collect($tahap)->firstWhere('state', 'current');
    $terakhir = count($tahap) > 1 ? end($tahap) : null;
    $kelompok = fn (string $label, int $i) => $i === 0 ? 'Hormat Kami,' : match (true) {
        in_array($label, ['Accounting', 'Finance'], true) => 'Diverifikasi,',
        $label === 'MW / BA' => 'Mengetahui,',
        default => 'Menyetujui,',
    };
    $p = $memo['pajak'];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Memo Urgent {{ $memo['number'] }}</title>
<style>
    @page { size: A4; margin: 12mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 0; line-height: 1.4; background: #fff; }
    .lembar { position: relative; }
    .atas { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .logo { width: 120px; height: 62px; border-radius: 10px; object-fit: contain; }
    .judul { flex: 1; text-align: center; }
    .judul h1 { font-size: 22px; letter-spacing: .06em; margin: 4px 0 2px; }
    .judul .no { font-size: 13px; }
    .kanan { width: 220px; text-align: right; font-size: 10px; color: #555; white-space: nowrap; }
    /* Stempel URGENT: mendatar (tidak miring), kotak lebar di bawah tulisan Created By. */
    .stempel { display: block; width: 230px; margin: 10px 0 0 auto; border: 3px double #c81e1e; color: #c81e1e; text-align: center;
        font-size: 26px; font-weight: 900; letter-spacing: .3em; padding: 6px 0 6px .3em; border-radius: 4px; opacity: .9; }
    table { border-collapse: collapse; width: 100%; }
    .kepala { margin-top: 10px; }
    .kepala td { border: 1px solid #999; padding: 3px 6px; vertical-align: top; }
    .kepala .l { width: 150px; } .kepala .s { width: 12px; text-align: center; }
    p { margin: 10px 0 6px; text-align: justify; }
    .rinci th, .rinci td { border: 1px solid #999; padding: 4px 6px; }
    .rinci th { background: #f3f3f3; font-size: 11px; text-transform: uppercase; }
    .r { text-align: right; font-variant-numeric: tabular-nums; } .c { text-align: center; }
    .rinci .tot td { font-weight: bold; background: #f3f3f3; }
    .terbilang { border: 1px solid #999; border-top: 0; padding: 4px 6px; background: #fff7ed; }
    .terbilang b { color: #c2410c; }
    .isi { margin-top: 10px; border: 1px solid #999; }
    .isi .h { background: #f3f3f3; padding: 3px 6px; font-weight: bold; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #999; }
    .isi .b { padding: 6px; min-height: 70px; white-space: pre-line; }
    .ttd { margin-top: 22px; table-layout: fixed; }
    .ttd td { text-align: center; vertical-align: top; padding: 0 6px; }
    .ttd .cap { height: 18px; }
    .ttd .ruang { height: 74px; display: flex; align-items: center; justify-content: center; }
    .ttd img { max-height: 64px; max-width: 100%; }
    .ttd .garis { border-top: 1px solid #555; padding-top: 3px; font-weight: bold; min-height: 18px; }
    .ttd .jab { color: #c2410c; font-weight: bold; font-size: 11px; }
    .ttd .wkt { color: #777; font-size: 9px; }
    .cap-ok, .cap-tolak { display: inline-block; transform: rotate(-8deg); border: 2px solid #047857; color: #047857; font-weight: bold; padding: 1px 6px; font-size: 10px; }
    .cap-tolak { border-color: #b91c1c; color: #b91c1c; }
    .tunggu { color: #b45309; font-size: 11px; }
    .kaki { margin-top: 14px; display: flex; justify-content: space-between; font-size: 9px; color: #777; }
    @media screen { body { max-width: 210mm; margin: 0 auto; padding: 16px; } }
</style>
</head>
<body @unless ($preview) onload="window.print()" @endunless>
<div class="lembar">
    <div class="atas">
        <img class="logo" src="/images/mup-logo.jpeg" alt="{{ $memo['perusahaan'] }}">
        <div class="judul">
            <h1>MEMO URGENT</h1>
            <div class="no">No : <b>{{ $memo['number'] }}</b></div>
        </div>
        <div class="kanan">
            Created By Java Core System ({{ now()->format('d-m-Y') }})<br>
            <span class="stempel">URGENT</span>
        </div>
    </div>

    <table class="kepala">
        <tr><td class="l">Kepada Yth</td><td class="s">:</td><td>{{ $berikut ? $sebut($berikut) : ($status === 'approved' ? '— (sudah disetujui penuh)' : '—') }}</td></tr>
        <tr><td class="l">Tembusan</td><td class="s">:</td><td>{{ $sebut($terakhir) }}</td></tr>
        <tr><td class="l">Dari</td><td class="s">:</td><td>{{ $memo['pengaju'] ?? '—' }}</td></tr>
        <tr><td class="l">Departemen</td><td class="s">:</td><td>{{ $memo['department'] ?? '—' }}</td></tr>
        <tr><td class="l">Hari / Tanggal</td><td class="s">:</td><td>{{ $hari($memo['date']) }}</td></tr>
        <tr><td class="l">Supplier</td><td class="s">:</td><td>{{ $memo['supplier'] ?? '—' }}</td></tr>
        <tr><td class="l">Perihal</td><td class="s">:</td><td><b>{{ $memo['subject'] }}</b></td></tr>
    </table>

    <p>Dengan hormat, sehubungan dengan keperluan <b>{{ $memo['subject'] }}</b>, maka kami mengajukan permohonan pembayaran sebesar
        <b>Rp {{ $rp2($memo['amount']) }}</b> dengan rincian sebagai berikut :</p>

    <table class="rinci">
        <thead><tr><th style="width:36px">No.</th><th>Uraian Pembayaran</th><th style="width:170px">Jumlah (Rp)</th></tr></thead>
        <tbody>
            <tr><td class="c">1</td><td>{{ $memo['supplier'] ? $memo['supplier'].' — ' : '' }}{{ $memo['subject'] }}</td>
                <td class="r">{{ $rp2($p ? $p['nilai_transaksi'] : $memo['amount']) }}</td></tr>
            @if ($p)
                @if ($p['incl'])<tr><td></td><td class="r">DPP (Nilai Transaksi termasuk PPN)</td><td class="r">{{ $rp2($p['dpp']) }}</td></tr>@endif
                <tr><td></td><td class="r">PPN {{ $p['ppn_mode'] === 'with' ? rtrim(rtrim(number_format($p['ppn_pct'], 2, ',', '.'), '0'), ',').'%' : '(tanpa PPN)' }}</td>
                    <td class="r">{{ $rp2($p['ppn']) }}</td></tr>
                <tr><td></td><td class="r">
                    @if ($p['pph_mode'] === 'none') PPh (tanpa PPh)
                    @else PPh {{ ['pph21' => '21', 'pph23' => '23', 'pph4_2' => '4(2)'][$p['pph_type']] ?? '' }} {{ rtrim(rtrim(number_format($p['pph_pct'], 2, ',', '.'), '0'), ',') }}% — {{ $p['pph_mode'] === 'borne' ? 'ditanggung perusahaan' : 'dipotong' }}
                    @endif</td>
                    <td class="r">{{ $p['pph_mode'] === 'withheld' ? '- '.$rp2($p['pph']) : ($p['pph_mode'] === 'borne' ? '('.$rp2($p['pph_journal']).')' : $rp2(0)) }}</td></tr>
            @endif
            <tr class="tot"><td></td><td class="r">TOTAL BAYAR</td><td class="r">{{ $rp2($memo['amount']) }}</td></tr>
        </tbody>
    </table>
    @if ($memo['terbilang'])<div class="terbilang"><b>Terbilang :</b> {{ $memo['terbilang'] }}</div>@endif

    <div class="isi"><div class="h">Isi Memo</div><div class="b">{{ $memo['body'] }}</div></div>

    <table class="ttd">
        <tr>@foreach ($tahap as $i => $s)<td class="cap">{{ $kelompok($s['label'], $i) }}</td>@endforeach<td class="cap">Dibayarkan oleh,</td></tr>
        <tr>
            @foreach ($tahap as $s)
                <td>
                    <div class="ruang">
                        @if ($s['state'] === 'done' && $s['signature'])<img src="{{ $s['signature'] }}" alt="TTD {{ $s['by'] }}">
                        @elseif ($s['state'] === 'done')<span class="cap-ok">DISETUJUI</span>
                        @elseif ($s['state'] === 'rejected')<span class="cap-tolak">DITOLAK</span>
                        @elseif ($s['state'] === 'current')<span class="tunggu">Menunggu…</span>
                        @endif
                    </div>
                    <div class="garis">{{ ($o = $orang($s)) !== '' ? $o : '(………………)' }}</div>
                    <div class="jab">{{ $s['label'] }}</div>
                    <div class="wkt">{{ in_array($s['state'], ['done', 'rejected'], true) ? ($jam($s['at']) ?? "\u{00a0}") : "\u{00a0}" }}</div>
                </td>
            @endforeach
            <td>
                <div class="ruang">
                    @if ($pembayaran && $pembayaran['ttd'])<img src="{{ $pembayaran['ttd'] }}" alt="TTD {{ $pembayaran['oleh'] }}">
                    @elseif ($pembayaran)<span class="cap-ok">DIBAYAR</span>
                    @endif
                </div>
                <div class="garis">{{ $pembayaran['oleh'] ?? '(………………)' }}</div>
                <div class="jab">Finance</div>
                <div class="wkt">{{ $pembayaran ? $pembayaran['nomor'].' · '.$pembayaran['tanggal'] : 'Belum dibayar' }}</div>
            </td>
        </tr>
    </table>

    <div class="kaki">
        <span>Status: {{ $statusLabel }} · dicetak {{ now()->format('d/m/Y H:i') }} oleh {{ $dicetakOleh }}</span>
        <span>TTD distempel otomatis dari persetujuan di Approval Inbox</span>
    </div>
</div>
</body>
</html>
