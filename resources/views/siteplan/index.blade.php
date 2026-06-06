<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site Plan — Pilih Proyek</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 32px 16px; }
        .wrap { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 6px; }
        p.sub { color: #94a3b8; margin: 0 0 24px; font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        a.card { display: block; text-decoration: none; background: linear-gradient(135deg, #06b6d4, #0e7490); color: #fff;
                 border-radius: 14px; padding: 20px; box-shadow: 0 10px 30px -8px rgba(0,0,0,.5); transition: transform .2s; }
        a.card:hover { transform: translateY(-4px); }
        .card .code { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: .85; }
        .card .name { font-size: 18px; font-weight: 800; margin-top: 4px; }
        .empty { color: #94a3b8; padding: 40px; text-align: center; border: 1px dashed #334155; border-radius: 12px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Site Plan Java Residence</h1>
        <p class="sub">Pilih proyek untuk melihat denah & ketersediaan unit.</p>

        @if ($projects->isEmpty())
            <div class="empty">Belum ada site plan yang dipublikasikan.</div>
        @else
            <div class="grid">
                @foreach ($projects as $p)
                    <a class="card" href="{{ route('siteplan.show', $p->code) }}">
                        <div class="code">{{ $p->code }}</div>
                        <div class="name">{{ $p->name }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
