@php($approved = ! is_null($r->urutan))
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $approved ? 'Nomor Urut NUP Anda' : 'Pendaftaran NUP Diterima' }}</title>
</head>
<body style="font-family: -apple-system, system-ui, sans-serif; line-height: 1.5; color: #111; max-width: 560px; margin: 0 auto; padding: 24px;">
    @if ($approved)
        <h2 style="color: #1e40af;">Nomor Urut NUP Anda telah terbit</h2>

        <p>Halo <strong>{{ $r->nama_pendaftar }}</strong>,</p>
        <p>Selamat, data Anda telah <strong>diverifikasi dan disetujui</strong>. Berikut detail Nomor Urut Pemilihan (NUP) Anda:</p>

        <table style="border-collapse: collapse; margin: 16px 0;">
            <tr><td style="padding: 6px 12px;"><strong>No NUP</strong></td><td style="padding: 6px 12px; font-family: monospace;">{{ $r->no_nup }}</td></tr>
            <tr><td style="padding: 6px 12px;"><strong>Urutan</strong></td><td style="padding: 6px 12px;">{{ $r->urutan }}</td></tr>
            <tr><td style="padding: 6px 12px;"><strong>Event</strong></td><td style="padding: 6px 12px;">{{ $r->event?->name ?? '-' }}</td></tr>
            <tr><td style="padding: 6px 12px;"><strong>Tanggal launching</strong></td><td style="padding: 6px 12px;">{{ optional($r->event?->tgl_launching)->translatedFormat('d M Y') ?? '-' }}</td></tr>
            @if ($r->projectPref)
                <tr><td style="padding: 6px 12px;"><strong>Proyek pilihan</strong></td><td style="padding: 6px 12px;">{{ $r->projectPref->name }}</td></tr>
            @endif
        </table>

        <p>Mohon hadir di hari launching membawa identitas diri. Detail lokasi & rundown akan kami kirim mendekati hari-H.</p>
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
        Email ini dikirim otomatis oleh sistem J-ERP. Jangan balas email ini.<br>
        Untuk pertanyaan, hubungi tim sales atau marketing kami.
    </p>
</body>
</html>
