{{-- Cetak lembar Memo Urgent — HTML siap-cetak (dialog cetak → Simpan sebagai PDF). Dipanggil ApprovalInboxController::cetak.
     TTD hanya untuk tahap yang SUDAH menyetujui; tahap berikutnya kosong sampai disetujui. --}}
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp '.number_format((float) $v, 0, ',', '.');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d F Y') : '—';
    $statusLabel = ['approved' => 'DISETUJUI', 'rejected' => 'DITOLAK', 'cancelled' => 'DIBATALKAN'][$status] ?? 'DALAM PROSES APPROVAL';
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $memo['number'] }} — Memo Urgent</title>
<style>
    @page { size: A4; margin: 16mm 16mm 18mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11.5px; color: #111; margin: 0; }
    .kop { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #111; padding-bottom: 6px; }
    .kop h1 { font-size: 17px; margin: 0; letter-spacing: .04em; }
    .kop .co { font-size: 11px; color: #444; }
    .no { font-family: 'Courier New', monospace; font-size: 13px; font-weight: bold; text-align: right; }
    .st { font-size: 10px; color: #b45309; font-family: Arial, sans-serif; }
    .meta { margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 3px 22px; }
    .meta div { display: flex; gap: 8px; } .meta b { width: 70px; flex: none; font-weight: normal; color: #555; }
    .isi { margin-top: 16px; min-height: 120px; white-space: pre-line; line-height: 1.6; }
    .ttd { margin-top: 26px; width: 100%; border-collapse: collapse; table-layout: fixed; }
    .ttd td { border: 1px solid #bbb; text-align: center; font-size: 10px; vertical-align: top; padding: 4px; }
    .ttd .j { font-weight: bold; background: #f2f2f2; }
    .ttd .box { height: 64px; display: flex; align-items: center; justify-content: center; }
    .ttd img { max-height: 54px; max-width: 100%; }
    .cap { display: inline-block; transform: rotate(-8deg); border: 2px solid #047857; color: #047857; font-weight: bold; padding: 1px 5px; font-size: 9px; }
    .cap.tolak { border-color: #b91c1c; color: #b91c1c; }
    .tunggu { color: #b45309; }
    .nm { font-weight: bold; } .tg { color: #666; }
    .foot { margin-top: 16px; display: flex; justify-content: space-between; font-size: 9px; color: #777; }
    @media screen { body { max-width: 210mm; margin: 12px auto; padding: 0 14px; } }
</style>
</head>
<body onload="window.print()">
    <div class="kop">
        <div><h1>MEMO URGENT</h1><div class="co">{{ $memo['perusahaan'] }}</div></div>
        <div class="no">{{ $memo['number'] }}<br><span class="st">{{ $statusLabel }}</span></div>
    </div>

    <div class="meta">
        <div><b>Tanggal</b> {{ $tgl($memo['date']) }}</div>
        <div><b>Dari</b> {{ $memo['pengaju'] ?? '—' }}{{ $memo['department'] ? ' · '.$memo['department'] : '' }}</div>
        <div><b>Supplier</b> {{ $memo['supplier'] ?? '—' }}</div>
        <div><b>Nominal</b> <strong>{{ $rp($memo['amount']) }}</strong></div>
        <div style="grid-column: span 2"><b>Perihal</b> <strong>{{ $memo['subject'] }}</strong></div>
    </div>

    <div class="isi">{{ $memo['body'] }}</div>

    <table class="ttd">
        <tr>@foreach ($stages as $s)<td class="j">{{ $s['label'] }}</td>@endforeach</tr>
        <tr>
            @foreach ($stages as $s)
                <td>
                    <div class="box">
                        @if ($s['state'] === 'done' && $s['signature'])<img src="{{ $s['signature'] }}" alt="TTD {{ $s['by'] }}">
                        @elseif ($s['state'] === 'done')<span class="cap">DISETUJUI</span>
                        @elseif ($s['state'] === 'rejected')<span class="cap tolak">DITOLAK</span>
                        @elseif ($s['state'] === 'current')<span class="tunggu">Menunggu…</span>
                        @endif
                    </div>
                    <div class="nm">{{ in_array($s['state'], ['done', 'rejected'], true) ? ($s['by'] ?? '—') : '(…………………)' }}</div>
                    <div class="tg">{{ in_array($s['state'], ['done', 'rejected'], true) && $s['at'] ? \Illuminate\Support\Carbon::parse($s['at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') : "\u{00a0}" }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="foot">
        <span>Dicetak {{ now()->format('d/m/Y H:i') }} oleh {{ $dicetakOleh }} · Java Core System</span>
        <span>TTD distempel otomatis dari persetujuan di Approval Inbox</span>
    </div>
</body>
</html>
