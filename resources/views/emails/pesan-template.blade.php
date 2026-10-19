<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; padding:0; background:#f4f4f5; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <div style="max-width:600px; margin:0 auto; padding:24px 16px;">
        <div style="background:#ffffff; border-radius:10px; padding:24px; font-size:14px; line-height:1.6;">
            {{-- Isi dari Pengaturan → Template Pesan. Nilai kata kunci sudah di-escape. --}}
            {!! $isiHtml !!}
        </div>
        <p style="text-align:center; font-size:11px; color:#9ca3af; margin-top:12px;">
            Email ini dikirim otomatis oleh sistem. Mohon tidak membalas email ini.
        </p>
    </div>
</body>
</html>
