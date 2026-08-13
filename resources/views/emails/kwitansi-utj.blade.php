@php
    // Sengaja disamakan kata demi kata dengan template WhatsApp "pembayaran UTJ
    // diterima", supaya customer yang menerima keduanya tidak merasa dikirimi dua
    // pesan berbeda.
    $nominal = number_format((float) ($r->xendit_paid_amount ?: ($r->booking_fee_paid ?: $r->utj_amount)), 0, ',', '.');
    $acara = $r->event?->name ?: ($r->projectPref?->name ?: '-');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pembayaran UTJ Diterima</title>
</head>
<body style="font-family: -apple-system, system-ui, sans-serif; line-height: 1.5; color: #111; max-width: 560px; margin: 0 auto; padding: 24px;">
    <p>Kepada Yth.<br>Bapak/Ibu <strong>{{ $r->nama_pendaftar }}</strong></p>

    <p>Pembayaran UTJ sebesar <strong>Rp {{ $nominal }}</strong> untuk NUP <strong>{{ $acara }}</strong> telah kami terima.</p>

    <p>Kwitansi terlampir pada email ini.</p>

    <p>Terima kasih atas kepercayaan Bapak/Ibu.</p>

    <p>Hormat Kami,<br><strong>PT Mitra Usaha Propertindo</strong></p>
</body>
</html>
