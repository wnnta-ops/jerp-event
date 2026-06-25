<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Peringatan — {{ $sp->no_surat }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 34px 44px; line-height: 1.5; }
        .head { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .logo { width: 46px; height: 46px; background: #ea7a1d; color: #fff; font-weight: 900; font-size: 26px; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .head .co { flex: 1; text-align: center; }
        .head .co .pt { font-weight: 800; font-size: 15px; letter-spacing: .3px; }
        table.meta { width: 100%; border-collapse: collapse; margin: 14px 0 6px; }
        table.meta td { padding: 1px 4px; vertical-align: top; }
        table.meta td.k { width: 52px; }
        ol.body { margin: 6px 0; padding-left: 22px; }
        ol.body > li { margin-bottom: 6px; text-align: justify; }
        .strong { font-weight: 700; }
        table.t { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 11.5px; }
        table.t th, table.t td { border: 1px solid #555; padding: 4px 7px; }
        table.t th { background: #f1f5f9; text-align: center; font-weight: 700; }
        table.t td.r { text-align: right; font-variant-numeric: tabular-nums; }
        table.t td.c { text-align: center; }
        table.t tfoot td { font-weight: 800; }
        .sign { margin-top: 26px; }
        .sign .nm { margin-top: 56px; font-weight: 700; text-decoration: underline; }
        .cc { margin-top: 18px; font-size: 11px; }
        .nb { margin-top: 10px; font-size: 11px; }
        .actions { margin-top: 22px; }
        @media print { .actions { display: none; } body { padding: 16px; } }
        .btn { display:inline-block; padding:8px 16px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; }
    </style>
</head>
@php
    use Illuminate\Support\Carbon;
    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
    $tgl = fn ($d) => $d ? Carbon::parse($d)->translatedFormat('d M Y') : '—';
    $roman = $sp->level_roman; // I/II/III ('' utk Pembatalan)
    $hal = $sp->level === 'PEMBATALAN'
        ? 'Pemberitahuan Pembatalan Pemesanan atas keterlambatan pembayaran Uang Muka/Angsuran'
        : 'Peringatan Keterlambatan pembayaran Uang Muka/Angsuran (Surat Peringatan '.$roman.')';
    $items = $sp->items ?? [];
@endphp
<body>
    <div class="head">
        <div class="logo">M</div>
        <div class="co"><div class="pt">{{ $settings->sp_company_name ?? 'PT. MITRA USAHA PROPERTINDO' }}</div></div>
    </div>

    <table class="meta">
        <tr><td class="k">Nomor</td><td>: {{ $sp->no_surat }}</td></tr>
        <tr><td class="k">Hal</td><td>: {{ $hal }}</td></tr>
    </table>

    <p style="margin:14px 0 2px;">Kepada Yth.</p>
    <p style="margin:0;">Bapak / Ibu <span class="strong">{{ $sp->nama }}</span>
        @if ($sp->alamat)<br><span style="white-space:pre-line;color:#444;">{{ $sp->alamat }}</span>@endif
    </p>

    <p style="margin:14px 0 2px;">Dengan hormat,</p>
    <p style="margin:0 0 4px;">Menindaklanjuti progress pembayaran Angsuran/Uang Muka hingga saat ini, dapat kami sampaikan sebagai berikut:</p>

    <ol class="body">
        <li>Bahwa terdapat transaksi Jual Beli Rumah antara {{ $settings->sp_company_name ?? 'PT. Mitra Usaha Propertindo' }}
            dengan Bapak/Ibu <span class="strong">{{ $sp->nama }}</span> berdasarkan Surat Pemesanan Tanah dan Bangunan
            No. {{ $sp->ref_spr ?: ($sp->kontrak?->no_kontrak) }};</li>
        <li>Bahwa berdasarkan point 1 tersebut diatas, Pihak Pembeli sepakat bahwa pembayaran transaksi Jual-Beli tersebut
            dilakukan dengan menggunakan inhouse dan/atau Kredit Pemilikan Rumah pada bank terkait;</li>
        <li>Bahwa Pihak Pembeli, berdasarkan catatan keuangan kami telah menunggak Angsuran tersebut sejak:
            <table class="t">
                <thead>
                    <tr>
                        <th>Tgl. Tagihan</th><th>Keterangan</th><th>Tagihan (Rp.)</th>
                        <th>Pembayaran (Rp.)</th><th>Kekurangan (Rp.)</th><th>Hari</th><th>Denda (Rp.)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $it)
                        <tr>
                            <td class="c">{{ $tgl($it['tgl'] ?? null) }}</td>
                            <td>{{ $it['keterangan'] ?? '' }}</td>
                            <td class="r">{{ $rp($it['tagihan'] ?? 0) }}</td>
                            <td class="r">{{ $rp($it['pembayaran'] ?? 0) }}</td>
                            <td class="r">{{ $rp($it['kekurangan'] ?? 0) }}</td>
                            <td class="c">{{ (int) ($it['hari'] ?? 0) }}</td>
                            <td class="r">{{ $rp($it['denda'] ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="c" style="color:#94a3b8;">Tidak ada tunggakan dipilih.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="r">Total</td>
                        <td class="r">{{ $rp($sp->total_tagihan) }}</td>
                        <td class="r">{{ $rp($sp->total_pembayaran) }}</td>
                        <td class="r">{{ $rp($sp->total_kekurangan) }}</td>
                        <td class="c"></td>
                        <td class="r">{{ $rp($sp->total_denda) }}</td>
                    </tr>
                </tfoot>
            </table>
        </li>
        <li>Bahwa oleh karenanya melalui surat ini kami meminta kepada Saudara/Saudari supaya segera melunasi keterlambatan
            pembayaran tersebut kepada kami selambat-lambatnya {{ (int) ($settings->sp_due_days ?: 7) }} (tujuh) hari kalender
            dengan jatuh tempo tanggal <span class="strong">{{ $tgl($sp->jatuh_tempo) }}</span>;</li>
        @if ($sp->level !== 'PEMBATALAN')
            <li>Bahwa apabila melalui Surat Teguran ke {{ $roman }} ini Saudara/Saudari tetap tidak ada tanggapan positif
                dan/atau Itikad Baik untuk melunasi, <span class="strong">maka dengan terpaksa kami akan melakukan tindakan
                dan/atau upaya hukum sesuai dengan perjanjian kontraktual yang tertuang dalam Surat Pemesanan yang telah
                ditandatangani secara bersama-sama.</span></li>
        @else
            <li><span class="strong">Bahwa berdasarkan ketentuan kontraktual, dengan ini kami sampaikan PEMBATALAN atas
                pemesanan tersebut sesuai Surat Pemesanan yang telah ditandatangani bersama.</span></li>
        @endif
    </ol>

    <p style="text-align:justify;margin:10px 0;">Apabila Saudara sudah melakukan pembayaran sebelum surat ini diterima, mohon
        abaikan Surat Peringatan ini dan menyampaikan kepada kami bukti bayar/ bukti transfer tersebut. Demikian Surat Kami
        Agar Saudara memperhatikan dengan seksama. Atas perhatiannya kami ucapkan terima kasih.</p>

    <div class="sign">
        <div>Sidoarjo, {{ $tgl($sp->tgl_surat) }}</div>
        <div>Hormat kami,</div>
        <div class="nm">{{ $sp->penandatangan_nama ?: $settings->sp_signer_name }}</div>
        <div>{{ $sp->penandatangan_jabatan ?: $settings->sp_signer_title }}</div>
    </div>

    <div class="cc">CC : - Legal<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;- CFO</div>
    <div class="nb">NB: Untuk keterangan / Informasi lebih lanjut dapat menghubungi Bag. Collection
        Telp. {{ $settings->sp_collection_phone }}</div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
