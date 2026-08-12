<x-filament-panels::page>
    @php
        $tiles = $this->getTiles();
        $user = auth()->user();
        // Jam aplikasi disimpan UTC (config app.timezone), jadi WAJIB dikonversi
        // sebelum ditampilkan — tanpa ini jam & salam meleset 7 jam.
        $nowWib = now()->setTimezone('Asia/Jakarta');
        $hour = (int) $nowWib->format('H');
        $salam = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 19 ? 'Selamat sore' : 'Selamat malam'));
        $firstName = \Illuminate\Support\Str::before($user?->full_name ?? $user?->name ?? 'Yogi', ' ');
    @endphp

    <style>
        /* ── Raw CSS grid (bypass Tailwind responsive) ──────────── */
        .fo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.625rem;
        }
        @media (min-width: 640px)  { .fo-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; } }
        @media (min-width: 768px)  { .fo-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .fo-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .fo-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); } }

        /* ── Entrance / idle animations ─────────────────────────── */
        @keyframes foFadeUp { from { opacity: 0; transform: translateY(20px) scale(.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes foHeader { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes foPulseDot {
            0%   { box-shadow: 0 0 0 0 rgba(239,68,68,.55); }
            70%  { box-shadow: 0 0 0 10px rgba(239,68,68,0); }
            100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
        }
        @keyframes foFloat {
            0%, 100% { transform: translateY(0) rotate(0); }
            50%      { transform: translateY(-4px) rotate(-2deg); }
        }
        @keyframes foGradientShift {
            0%, 100% { background-position: 0% 50%; }
            50%      { background-position: 100% 50%; }
        }
        @keyframes foSparkle {
            0%, 100% { opacity: 0; transform: scale(0.6) rotate(0deg); }
            50%      { opacity: 1; transform: scale(1) rotate(180deg); }
        }

        .fo-header { animation: foHeader .55s ease-out both; }
        .fo-header-sub { animation: foHeader .55s ease-out .15s both; }

        /* ── Tile (landscape, denser grid) ──────────────────────── */
        .fo-tile {
            position: relative;
            overflow: hidden;
            border-radius: 0.875rem;
            background-size: 200% 200%;
            animation: foFadeUp .5s cubic-bezier(.34,1.56,.64,1) backwards,
                       foGradientShift 10s ease-in-out infinite;
            transition: transform .3s cubic-bezier(.34,1.56,.64,1),
                        box-shadow .3s ease-out,
                        filter .3s ease-out;
            transform: translateZ(0);
            will-change: transform;
            box-shadow: 0 3px 10px rgba(0,0,0,.10), inset 0 1px 0 rgba(255,255,255,.18);
            cursor: pointer;
        }
        .fo-tile:nth-child(1)  { animation-delay: 0s,    0s; }
        .fo-tile:nth-child(2)  { animation-delay: .04s,  0s; }
        .fo-tile:nth-child(3)  { animation-delay: .08s,  0s; }
        .fo-tile:nth-child(4)  { animation-delay: .12s,  0s; }
        .fo-tile:nth-child(5)  { animation-delay: .16s,  0s; }
        .fo-tile:nth-child(6)  { animation-delay: .20s,  0s; }
        .fo-tile:nth-child(7)  { animation-delay: .24s,  0s; }
        .fo-tile:nth-child(8)  { animation-delay: .28s,  0s; }
        .fo-tile:nth-child(9)  { animation-delay: .32s,  0s; }
        .fo-tile:nth-child(10) { animation-delay: .36s,  0s; }
        .fo-tile:nth-child(11) { animation-delay: .40s,  0s; }

        .fo-tile:hover {
            transform: translateY(-5px) scale(1.03);
            box-shadow: 0 18px 36px -8px rgba(0,0,0,.35),
                        0 6px 16px -6px rgba(0,0,0,.22),
                        inset 0 1px 0 rgba(255,255,255,.30);
            filter: saturate(1.18) brightness(1.06);
        }
        .fo-tile:active {
            transform: translateY(-1px) scale(.99);
            transition-duration: .1s;
        }
        .fo-tile.disabled {
            opacity: .55;
            filter: grayscale(.6);
            cursor: not-allowed;
        }
        .fo-tile.disabled:hover {
            transform: none;
            filter: grayscale(.6);
            box-shadow: 0 3px 10px rgba(0,0,0,.10);
        }

        /* Icon with float + hover scale-rotate */
        .fo-icon {
            transition: transform .4s cubic-bezier(.34,1.56,.64,1),
                        background .3s, box-shadow .3s;
            backdrop-filter: blur(8px);
            animation: foFloat 3s ease-in-out infinite;
        }
        .fo-tile:hover .fo-icon {
            transform: scale(1.18) rotate(-8deg);
            background: rgba(255,255,255,.32);
            box-shadow: 0 6px 18px -3px rgba(0,0,0,.28);
            animation-play-state: paused;
        }

        /* Diagonal shine sweep on hover */
        .fo-shine {
            position: absolute; inset: 0;
            background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,.32) 50%, transparent 65%);
            transform: translateX(-130%) skewX(-15deg);
            transition: transform .8s cubic-bezier(.4,0,.2,1);
            pointer-events: none;
        }
        .fo-tile:hover .fo-shine { transform: translateX(130%) skewX(-15deg); }

        /* Sparkle dots that appear on hover */
        .fo-sparkle {
            position: absolute; width: 4px; height: 4px; border-radius: 50%;
            background: white;
            opacity: 0; pointer-events: none;
        }
        .fo-tile:hover .fo-sparkle { animation: foSparkle 1.4s ease-in-out infinite; }
        .fo-sparkle.s1 { top: 18%; left: 22%; }
        .fo-sparkle.s2 { top: 70%; left: 78%; animation-delay: .2s !important; }
        .fo-sparkle.s3 { top: 30%; right: 18%; animation-delay: .4s !important; }
        .fo-sparkle.s4 { bottom: 24%; left: 30%; animation-delay: .6s !important; }

        /* Notification badge */
        .fo-badge {
            position: absolute; top: .5rem; right: .5rem;
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; padding: 0 .35rem;
            border-radius: 999px; font-size: .68rem; font-weight: 700; color: white;
            background: rgba(15,23,42,.6);
            border: 1px solid rgba(255,255,255,.35);
            backdrop-filter: blur(6px);
            animation: foFadeUp .5s cubic-bezier(.34,1.56,.64,1) .4s backwards;
            z-index: 2;
        }
        .fo-badge.urgent {
            background: #ef4444;
            animation: foFadeUp .5s cubic-bezier(.34,1.56,.64,1) .4s backwards,
                       foPulseDot 2s ease-out 1s infinite;
        }
        .fo-chip {
            position: absolute; top: .5rem; right: .5rem;
            padding: .1rem .45rem; border-radius: 4px;
            background: rgba(15,23,42,.5); border: 1px solid rgba(255,255,255,.25);
            font-size: .55rem; text-transform: uppercase; letter-spacing: .1em; color: rgba(255,255,255,.95); font-weight: 600;
            z-index: 2;
        }

        /* Decorative orbs */
        .fo-orb-a, .fo-orb-b { position: absolute; border-radius: 50%; pointer-events: none; transition: transform .8s ease-out; }
        .fo-orb-a { right: -1.5rem; top: -1.5rem; width: 5.5rem; height: 5.5rem; background: rgba(255,255,255,.18); filter: blur(12px); }
        .fo-orb-b { left: -2rem; bottom: -2rem; width: 5rem; height: 5rem; background: rgba(0,0,0,.10); filter: blur(20px); }
        .fo-tile:hover .fo-orb-a { transform: translate(-10px, 10px) scale(1.2); }
        .fo-tile:hover .fo-orb-b { transform: translate(10px, -10px) scale(1.2); }

        /* Label slide-up reveal on hover */
        .fo-label-wrap { transition: transform .35s cubic-bezier(.34,1.56,.64,1); }
        .fo-tile:hover .fo-label-wrap { transform: translateY(-2px); }

        @media (prefers-reduced-motion: reduce) {
            .fo-tile, .fo-icon, .fo-shine, .fo-orb-a, .fo-orb-b, .fo-header, .fo-header-sub, .fo-sparkle, .fo-badge {
                animation: none !important; transition: none !important;
            }
        }

        /* ── Tema "Java Core System" (mirip dashboard ipro-fo): kartu GELAP +
              aksen oranye, bukan gradien warna-warni. ──────────────────────── */
        /* Kartu tile: ORANGE SOLID satu warna (brand JC) — tanpa gradasi, tampilan modern flat. */
        .fo-tile {
            background: #ff8c00 !important;
            border: 1px solid rgba(255,255,255,.22);
            box-shadow: 0 4px 14px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.12) !important;
            animation: foFadeUp .5s cubic-bezier(.34,1.56,.64,1) backwards !important;
        }
        .fo-tile:hover {
            border-color: rgba(255,255,255,.45);
            box-shadow: 0 16px 34px -10px rgba(0,0,0,.45), 0 0 0 1px rgba(255,255,255,.25) !important;
            filter: none !important;
        }
        .fo-icon {
            background: rgba(255,255,255,.22) !important;
            border: 1px solid rgba(255,255,255,.35) !important;
            box-shadow: 0 6px 16px -4px rgba(0,0,0,.28) !important;
        }
        .fo-tile:hover .fo-icon { background: rgba(255,255,255,.32) !important; }
        .fo-orb-a { background: rgba(255,255,255,.14) !important; }
        .fo-orb-b { background: rgba(0,0,0,.14) !important; }
        .fo-shine { background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,.30) 50%, transparent 65%) !important; }
        .fo-sparkle { background: #fff !important; }
        .fo-label-wrap > div { color: #f1f5f9 !important; }
        /* Tile nonaktif tetap redup */
        .fo-tile.disabled { filter: grayscale(.5) brightness(.8) !important; }

        /* ── Kartu ringkasan (summary) — IKUT TEMA (terang/gelap) ─────────────── */
        .jc-stats { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: .75rem; margin-bottom: 1.25rem; }
        @media (min-width: 768px)  { .jc-stats { grid-template-columns: repeat(3, minmax(0,1fr)); } }
        @media (min-width: 1280px) { .jc-stats { grid-template-columns: repeat(5, minmax(0,1fr)); } }
        .jc-stat { position: relative; border-radius: 1rem; padding: 1.1rem 1.2rem;
            background: #ffffff; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,.07);
            animation: foFadeUp .5s cubic-bezier(.34,1.56,.64,1) backwards; }
        .dark .jc-stat { background: linear-gradient(150deg, #1e293b 0%, #0f172a 100%); border-color: rgba(255,140,0,.16); box-shadow: 0 4px 14px rgba(0,0,0,.25); }
        .jc-stat-accent, .dark .jc-stat-accent { background: #ff8c00 !important; border-color: rgba(255,255,255,.25); box-shadow: 0 10px 26px -8px rgba(255,140,0,.5); }
        .jc-stat-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .5rem; }
        .jc-stat-label { font-size: .72rem; font-weight: 600; color: #6b7280; }
        .jc-stat-accent .jc-stat-label { color: rgba(255,255,255,.92); }
        .jc-stat-icon { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 8px; background: rgba(255,140,0,.12); color: #ea580c; }
        .dark .jc-stat-icon { background: rgba(255,140,0,.15); color: #ff9a1a; }
        .jc-stat-accent .jc-stat-icon, .dark .jc-stat-accent .jc-stat-icon { background: rgba(255,255,255,.22); color: #fff; }
        .jc-stat-value { font-size: 1.7rem; font-weight: 800; line-height: 1.1; color: #111827; }
        .dark .jc-stat-value { color: #f8fafc; }
        .jc-stat-accent .jc-stat-value, .dark .jc-stat-accent .jc-stat-value { color: #fff; }
        .jc-stat-sub { font-size: .72rem; color: #9ca3af; margin-top: .25rem; }
        .jc-stat-accent .jc-stat-sub, .dark .jc-stat-accent .jc-stat-sub { color: rgba(255,255,255,.85); }

        /* ── Panel tren + pipeline — ikut tema ────────────────────────────────── */
        .jc-panels { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 1024px) { .jc-panels { grid-template-columns: 1.4fr 1fr; } }
        .jc-panel { border-radius: 1rem; padding: 1.2rem 1.3rem; background: #ffffff; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,.07); }
        .dark .jc-panel { background: linear-gradient(150deg, #1e293b 0%, #0f172a 100%); border-color: rgba(255,140,0,.12); box-shadow: 0 4px 14px rgba(0,0,0,.25); }
        .jc-panel-title { font-size: .9rem; font-weight: 800; color: #111827; margin-bottom: 1rem; }
        .dark .jc-panel-title { color: #f1f5f9; }
        .jc-chart { display: flex; align-items: flex-end; gap: .6rem; height: 180px; padding-top: .5rem; }
        .jc-bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .4rem; height: 100%; justify-content: flex-end; }
        .jc-bar { width: 100%; max-width: 46px; border-radius: 6px 6px 0 0; background: #ff8c00; min-height: 4px; transition: height .4s; }
        .jc-bar-label { font-size: .7rem; color: #6b7280; }
        .dark .jc-bar-label { color: #94a3b8; }
        .jc-pipe { display: flex; flex-direction: column; gap: .7rem; }
        .jc-pipe-row { display: flex; align-items: center; gap: .6rem; font-size: .8rem; }
        .jc-pipe-label { width: 96px; color: #6b7280; flex-shrink: 0; }
        .dark .jc-pipe-label { color: #94a3b8; }
        .jc-pipe-bar-wrap { flex: 1; height: 8px; border-radius: 999px; background: rgba(255,140,0,.12); overflow: hidden; }
        .jc-pipe-bar { display: block; height: 100%; border-radius: 999px; background: #ff8c00; }
        .jc-pipe-count { width: 36px; text-align: right; font-weight: 700; color: #111827; }
        .dark .jc-pipe-count { color: #f1f5f9; }
    </style>

    {{-- ── Welcome header ──────────────────────────────────────── --}}
    <div class="mb-5 md:mb-6">
        <div class="fo-header text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ $nowWib->translatedFormat('l, d F Y') }} · {{ $nowWib->format('H:i') }} WIB
        </div>
        <h1 class="fo-header text-xl md:text-3xl font-bold mt-1" style="color:#ff8c00;">
            {{ $salam }}, {{ $firstName }} 👋
        </h1>
        <p class="fo-header-sub text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Ringkasan aktivitas Java Core System hari ini.
        </p>
    </div>

    {{-- ── Kartu ringkasan (summary) ─────────────────────────── --}}
    <div class="jc-stats">
        @foreach ($this->getStats() as $s)
            <div class="jc-stat {{ $s['accent'] ? 'jc-stat-accent' : '' }}">
                <div class="jc-stat-top">
                    <span class="jc-stat-label">{{ $s['label'] }}</span>
                    <span class="jc-stat-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $s['icon'] }}"/>
                        </svg>
                    </span>
                </div>
                <div class="jc-stat-value">{{ $s['value'] }}</div>
                <div class="jc-stat-sub">{{ $s['sub'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ── Tren penjualan + Pipeline ─────────────────────────── --}}
    @php
        $trend = $this->getTrend();
        $maxT = max(1, max(array_map(fn ($x) => $x['nilai'], $trend)));
        $pipe = $this->getPipeline();
        $maxP = max(1, max(array_map(fn ($x) => $x['count'], $pipe)));
    @endphp
    <div class="jc-panels">
        <div class="jc-panel">
            <div class="jc-panel-title">📈 Tren Penjualan 6 Bulan</div>
            <div class="jc-chart">
                @foreach ($trend as $t)
                    <div class="jc-bar-col">
                        <div class="jc-bar" style="height: {{ max(4, round($t['nilai'] / $maxT * 100)) }}%"
                             title="{{ $t['count'] }} kontrak · Rp {{ number_format($t['nilai'], 0, ',', '.') }}"></div>
                        <div class="jc-bar-label">{{ $t['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="jc-panel">
            <div class="jc-panel-title">🔻 Pipeline Penjualan</div>
            <div class="jc-pipe">
                @foreach ($pipe as $p)
                    <div class="jc-pipe-row">
                        <span class="jc-pipe-label">{{ $p['label'] }}</span>
                        <span class="jc-pipe-bar-wrap"><span class="jc-pipe-bar" style="width: {{ max(3, round($p['count'] / $maxP * 100)) }}%"></span></span>
                        <span class="jc-pipe-count">{{ $p['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
