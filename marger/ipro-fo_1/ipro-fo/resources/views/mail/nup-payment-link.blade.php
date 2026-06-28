<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran NUP</title>
</head>
<body style="margin:0;background:#f4f5f7;font-family:'Segoe UI',Arial,sans-serif;color:#1a1a1a;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#ff8c00;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;">
            <div style="font-size:20px;font-weight:800;">Java Residence</div>
            <div style="font-size:12px;opacity:.9;">PT. Mitra Usaha Propertindo</div>
        </div>
        <div style="background:#fff;padding:24px;border-radius:0 0 12px 12px;box-shadow:0 4px 14px rgba(0,0,0,.06);">
            <p>Halo <strong>{{ $reg->customer?->full_name ?? 'Calon Pembeli' }}</strong>,</p>
            <p>Terima kasih telah mendaftar NUP (Nomor Urut Pemilihan). Berikut detail pendaftaran Anda:</p>

            <table style="width:100%;font-size:14px;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:6px 0;color:#666;">No. NUP</td><td style="padding:6px 0;text-align:right;font-weight:700;font-family:monospace;">{{ $reg->nup_number }}</td></tr>
                <tr><td style="padding:6px 0;color:#666;">Antrian</td><td style="padding:6px 0;text-align:right;">#{{ $reg->queue_no }}</td></tr>
                <tr><td style="padding:6px 0;color:#666;">Skema</td><td style="padding:6px 0;text-align:right;">{{ $reg->scheme?->name }}</td></tr>
                <tr><td style="padding:10px 0;color:#666;border-top:1px solid #eee;">Total Tagihan</td>
                    <td style="padding:10px 0;text-align:right;border-top:1px solid #eee;font-weight:800;font-size:18px;color:#b35a00;">
                        Rp {{ number_format((float) ($reg->scheme?->nup_amount ?? 0), 0, ',', '.') }}
                    </td></tr>
            </table>

            @if ($payLink)
                <div style="text-align:center;margin:24px 0;">
                    <a href="{{ $payLink }}" style="display:inline-block;background:#ff8c00;color:#fff;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:16px;">
                        💳 Bayar Sekarang
                    </a>
                </div>

                <p style="font-size:13px;color:#666;">Atau salin tautan berikut ke browser Anda:</p>
                <p style="font-size:12px;word-break:break-all;background:#f6f6f6;padding:10px;border-radius:6px;">{{ $payLink }}</p>
            @else
                <div style="margin:20px 0;padding:14px 16px;background:#fff7e6;border-radius:10px;color:#b35a00;font-size:14px;">
                    Pendaftaran Anda sudah <strong>tercatat</strong>. Tim kami akan menghubungi Anda untuk proses pembayaran NUP.
                </div>
            @endif

            <p style="font-size:12px;color:#999;margin-top:24px;border-top:1px solid #eee;padding-top:16px;">
                Email ini dikirim otomatis oleh Java Core System. Abaikan jika Anda tidak merasa mendaftar.
            </p>
        </div>
    </div>
</body>
</html>
