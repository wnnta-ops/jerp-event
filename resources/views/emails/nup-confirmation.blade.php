@php
    $approved = ! is_null($r->urutan);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $approved ? 'Nomor Urut NUP Anda' : 'Pendaftaran NUP Diterima' }}</title>
</head>
<body style="font-family: -apple-system, system-ui, sans-serif; line-height: 1.5; color: #111; max-width: 560px; margin: 0 auto; padding: 24px;">
    @if ($approved)
        @php
            $proj = $r->projectPref?->name ?? '';
            $ev = $r->event?->name ?? '';
            $sales = $r->agent?->sales_label ?: ($r->ref_sales_name ?: '-');
            $nik = $r->nik ?: ($r->customer?->nik ?: '-');
            $telp = $r->phone ?: ($r->customer?->mobile ?: '-');
        @endphp
        <p>Kepada Yth.<br>Bapak/Ibu <strong>{{ $r->nama_pendaftar }}</strong></p>

        <p>Kami mengucapkan Selamat kepada Bapak/Ibu telah menjadi calon pemesan atas unit properti di <strong>{{ trim($proj.' '.$ev) }}</strong> dengan :</p>

        <table style="border-collapse: collapse; margin: 14px 0;">
            <tr><td style="padding: 4px 12px;">No Urut NUP</td><td style="padding: 4px 12px;">: <strong>{{ $r->no_nup }}</strong></td></tr>
            <tr><td style="padding: 4px 12px;">Nama Calon Pemesan</td><td style="padding: 4px 12px;">: {{ $r->nama_pendaftar }}</td></tr>
            <tr><td style="padding: 4px 12px;">No Telepon/HP</td><td style="padding: 4px 12px;">: {{ $telp }}</td></tr>
            <tr><td style="padding: 4px 12px;">NIK</td><td style="padding: 4px 12px;">: {{ $nik }}</td></tr>
            <tr><td style="padding: 4px 12px;">Sales/Agen</td><td style="padding: 4px 12px;">: {{ $sales }}</td></tr>
        </table>

        <p>Terimakasih atas kepercayaan Bapak/Ibu telah memilih Java Residence sebagai hunian terbaik untuk Bapak/Ibu Sekeluarga.</p>

        <p>Hormat Kami,<br>Direktur<br><strong>PT Mitra Usaha Propertindo</strong></p>

        <p style="margin-top:16px; font-size:13px; color:#374151;"><em>Note: Harap membawa Dokumen terlampir di email ini (SPRS &amp; Kwitansi) pada saat Acara Pilih Unit {{ trim($proj.' '.$ev) }}.</em></p>

        @if (! empty($sprsUrl))
            <p style="margin-top:14px;"><a href="{{ $sprsUrl }}" style="display:inline-block; background:#2563eb; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600;">Lihat / Unduh SPRS + Kwitansi</a></p>
        @endif
    @else
        <h2 style="color: #1e40af;">Pendaftaran NUP berhasil</h2>

        <p>Halo <strong>{{ $r->nama_pendaftar }}</strong>,</p>
        <p>Terima kasih telah mendaftar. Data Anda akan <strong>diverifikasi oleh tim kami</strong> terlebih dahulu.</p>

        @if ($r->utj_amount > 0)
            <p>Untuk mengamankan pendaftaran, mohon lakukan pembayaran <strong>Uang Tanda Jadi (UTJ)</strong> berikut:</p>
            <table style="border-collapse: collapse; margin: 16px 0;">
                <tr><td style="padding: 6px 12px;"><strong>Nominal UTJ</strong></td><td style="padding: 6px 12px; font-weight: bold;">Rp {{ number_format((float) $r->utj_amount, 0, ',', '.') }}</td></tr>
                <tr><td style="padding: 6px 12px;"><strong>Event</strong></td><td style="padding: 6px 12px;">{{ $r->event?->name ?? '-' }}</td></tr>
                <tr><td style="padding: 6px 12px;"><strong>Tanggal launching</strong></td><td style="padding: 6px 12px;">{{ optional($r->event?->tgl_launching)->translatedFormat('d M Y') ?? '-' }}</td></tr>
                @if ($r->projectPref)
                    <tr><td style="padding: 6px 12px;"><strong>Proyek pilihan</strong></td><td style="padding: 6px 12px;">{{ $r->projectPref->name }}</td></tr>
                @endif
            </table>
            @if ($r->xendit_invoice_url)
                <p style="margin: 20px 0;">
                    <a href="{{ $r->xendit_invoice_url }}" style="background: #16a34a; color: #fff; padding: 12px 22px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">Bayar UTJ Sekarang</a>
                </p>
                <p style="font-size: 13px; color: #6b7280;">Atau buka tautan ini: <a href="{{ $r->xendit_invoice_url }}">{{ $r->xendit_invoice_url }}</a></p>
            @endif
            <p><strong>Nomor urut NUP</strong> akan diterbitkan setelah pembayaran terverifikasi dan data Anda disetujui tim kami.</p>
        @else
            <p><strong>Nomor urut NUP</strong> akan diterbitkan dan dikirim ke email ini setelah data Anda disetujui. Mohon cek email Anda secara berkala.</p>
            <table style="border-collapse: collapse; margin: 16px 0;">
                <tr><td style="padding: 6px 12px;"><strong>Event</strong></td><td style="padding: 6px 12px;">{{ $r->event?->name ?? '-' }}</td></tr>
                <tr><td style="padding: 6px 12px;"><strong>Tanggal launching</strong></td><td style="padding: 6px 12px;">{{ optional($r->event?->tgl_launching)->translatedFormat('d M Y') ?? '-' }}</td></tr>
                @if ($r->projectPref)
                    <tr><td style="padding: 6px 12px;"><strong>Proyek pilihan</strong></td><td style="padding: 6px 12px;">{{ $r->projectPref->name }}</td></tr>
                @endif
            </table>
        @endif
    @endif

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
    <p style="color: #6b7280; font-size: 13px;">
        Email ini dikirim otomatis oleh sistem Java Core. Jangan balas email ini.<br>
        Untuk pertanyaan, hubungi tim sales atau marketing kami.
    </p>
</body>
</html>
