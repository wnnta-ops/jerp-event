<x-filament-panels::page>
    @php
        $tiles = $this->getTiles();
        $user = auth()->user();
        $hour = (int) now()->format('H');
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
    </style>

    {{-- ── Welcome header ──────────────────────────────────────── --}}
    <div class="mb-5 md:mb-6">
        <div class="fo-header text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ now()->translatedFormat('l, d F Y') }} · {{ now()->format('H:i') }} WIB
        </div>
        <h1 class="fo-header text-xl md:text-3xl font-bold mt-1 bg-clip-text text-transparent"
            style="background-image: linear-gradient(90deg, #60a5fa 0%, #a78bfa 50%, #f472b6 100%);">
            {{ $salam }}, {{ $firstName }} 👋
        </h1>
        <p class="fo-header-sub text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Pilih modul untuk mulai bekerja.
        </p>
    </div>

    {{-- ── Tile grid (landscape, denser) ─────────────────────── --}}
    <div class="fo-grid">
        @foreach ($tiles as $t)
            @php
                $count = $t['count'] instanceof \Closure ? ($t['count'])() : $t['count'];
                $disabled = $t['disabled'] ?? false;
                $hasCount = ! $disabled && is_numeric($count) && (int) $count > 0;
                $urgent = $hasCount && in_array(\Illuminate\Support\Str::slug($t['label']), ['nup', 'master-customer', 'approval']);
            @endphp
            <a href="{{ $disabled ? '#' : $t['url'] }}"
               class="fo-tile {{ $disabled ? 'disabled' : '' }}"
               style="background: {{ $t['gradient'] }}; background-size: 200% 200%; aspect-ratio: 5/3;"
               @if ($disabled) onclick="event.preventDefault();" title="Belum tersedia" aria-disabled="true" @endif>

                <div class="fo-orb-a"></div>
                <div class="fo-orb-b"></div>
                <div class="fo-shine"></div>

                <span class="fo-sparkle s1"></span>
                <span class="fo-sparkle s2"></span>
                <span class="fo-sparkle s3"></span>
                <span class="fo-sparkle s4"></span>

                @if ($hasCount)
                    <span class="fo-badge {{ $urgent ? 'urgent' : '' }}">{{ $count > 99 ? '99+' : $count }}</span>
                @elseif ($disabled)
                    <span class="fo-chip">Menyusul</span>
                @endif

                <div class="relative h-full w-full flex flex-col items-center justify-center px-2 py-3 text-center">
                    <span class="fo-icon inline-grid place-items-center rounded-xl mb-2"
                          style="width:44px; height:44px; background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.35);">
                        <svg class="w-6 h-6 text-white drop-shadow" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $t['icon'] }}"/>
                        </svg>
                    </span>

                    <div class="fo-label-wrap">
                        <div class="text-sm md:text-base font-bold text-white drop-shadow leading-tight uppercase tracking-wide">
                            {{ $t['label'] }}
                        </div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
