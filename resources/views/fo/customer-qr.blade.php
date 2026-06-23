<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Customer — {{ $customer->full_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f1f5f9; color: #0f172a; padding: 24px; }
        .card { max-width: 380px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 24px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
        .brand { font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: #64748b; }
        .qr { margin: 16px auto; width: 280px; height: 280px; display: flex; align-items: center; justify-content: center; }
        .qr svg { width: 280px; height: 280px; }
        .code { font-family: ui-monospace, monospace; font-weight: 800; font-size: 20px; letter-spacing: 1px; color: #1e40af; margin-top: 4px; }
        .name { font-size: 18px; font-weight: 700; margin-top: 10px; }
        .meta { margin-top: 10px; font-size: 13px; color: #334155; line-height: 1.5; }
        .meta .lbl { color: #64748b; }
        .nup { display: inline-block; margin-top: 8px; background: #dbeafe; color: #1e40af; font-weight: 700; padding: 4px 12px; border-radius: 999px; font-size: 13px; }
        .actions { max-width: 380px; margin: 16px auto 0; display: flex; gap: 8px; }
        .btn { flex: 1; text-align: center; padding: 10px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; text-decoration: none; font-size: 14px; cursor: pointer; }
        .btn.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        @media print {
            body { background: #fff; padding: 0; }
            .card { border: 0; box-shadow: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Java Core · Kartu Customer</div>
        <div class="qr">{!! $svg !!}</div>
        <div class="code">{{ $code }}</div>
        <div class="name">{{ $customer->full_name }}</div>
        @if ($reg && $reg->no_nup)
            <div class="nup">No. NUP: {{ $reg->no_nup }}</div>
        @endif
    </div>

    <div class="actions">
        <a class="btn primary" href="javascript:window.print()">Cetak</a>
        <a class="btn" href="javascript:window.close()">Tutup</a>
    </div>
</body>
</html>
