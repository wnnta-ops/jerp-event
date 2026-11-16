<!DOCTYPE html>
<html lang="id" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ff8c00">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Scanner Event' }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/logo/logo-daun.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/event-scanner.js'])
    @livewireStyles
    {{-- Warna brand Java Core (oranye & putih), sama dengan dashboard. Ditulis di sini,
         bukan kelas Tailwind: aturan body global app.css berada di luar layer Tailwind
         dan akan mengalahkan kelas bg-/text-. --}}
    <style>
        :root { --jc-orange:#ff8c00; --jc-orange-dark:#e67600; --jc-orange-soft:#fff4e5; --jc-ink:#1f2937; --jc-muted:#6b7280; --jc-line:#e5e7eb; }
        body.jc-scan { background:#ffffff; color:var(--jc-ink); }
        .jc-brand { font-weight:800; letter-spacing:.06em; background:linear-gradient(90deg,#ffc266 0%,#ff8c00 45%,#ff7a00 100%);
                    -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
        .jc-muted { color:var(--jc-muted); }
        .jc-link { color:var(--jc-orange); text-decoration:underline; }
        .jc-card { display:flex; align-items:center; justify-content:space-between; gap:.75rem; width:100%; text-align:left;
                   border-radius:1rem; padding:1rem 1.1rem; transition:transform .1s ease, box-shadow .15s ease; }
        .jc-card-on { background:var(--jc-orange); color:#fff; border:2px solid var(--jc-orange); box-shadow:0 6px 16px rgba(255,140,0,.28); }
        .jc-card-on:active { transform:scale(.99); background:var(--jc-orange-dark); }
        .jc-card-on .jc-sub { color:rgba(255,255,255,.88); }
        .jc-card-off { background:#fff; color:var(--jc-ink); border:2px solid var(--jc-orange); box-shadow:0 0 14px rgba(255,140,0,.22); cursor:not-allowed; }
        .jc-card-off .jc-sub { color:var(--jc-muted); }
        .jc-off-tag { font-style:italic; color:var(--jc-orange); font-size:.9rem; white-space:nowrap; }
        .jc-pill { border-radius:.5rem; padding:.25rem .65rem; font-size:.8rem; font-weight:800; background:#fff; color:var(--jc-orange); white-space:nowrap; }
        .jc-header { background:linear-gradient(90deg,#ff9f1c 0%,#ff8c00 55%,#ff7a00 100%); color:#fff; box-shadow:0 2px 10px rgba(255,140,0,.3); }
        .jc-header-btn { background:rgba(255,255,255,.22); color:#fff; border-radius:.6rem; padding:.45rem .8rem; font-size:.85rem; }
        .jc-input { flex:1; border:1.5px solid var(--jc-line); border-radius:.8rem; padding:.75rem 1rem; font-size:1.05rem; background:#fff; color:var(--jc-ink); }
        .jc-input:focus { outline:none; border-color:var(--jc-orange); box-shadow:0 0 0 3px rgba(255,140,0,.18); }
        .jc-btn { background:var(--jc-orange); color:#fff; font-weight:800; border-radius:.8rem; padding:.75rem 1.25rem; }
        .jc-btn:disabled { opacity:.5; }
        .jc-btn-outline { background:#fff; color:var(--jc-orange); border:2px solid var(--jc-orange); border-radius:.8rem; padding:.7rem; width:100%; font-weight:700; }
        .jc-list { background:#fff; border:1px solid var(--jc-line); border-radius:1rem; overflow:hidden; }
        .jc-list > div + div { border-top:1px solid var(--jc-line); }
        .jc-sec { font-size:.85rem; font-weight:700; color:var(--jc-ink); margin-bottom:.5rem; }
        .jc-time { font-family:ui-monospace,monospace; font-variant-numeric:tabular-nums; color:var(--jc-ink); font-weight:700; }
        .jc-warn { background:var(--jc-orange-soft); color:#9a3412; border-radius:.6rem; padding:.75rem; font-size:.875rem; }
        .jc-pick { background:var(--jc-orange); color:#fff; border-radius:1rem; padding:1.4rem; font-size:1.8rem; font-weight:900; width:100%; box-shadow:0 6px 16px rgba(255,140,0,.28); }
    </style>
</head>
<body class="jc-scan min-h-dvh antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
