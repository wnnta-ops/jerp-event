<x-filament-panels::page>
    @php
        $tiles = $this->getSubTiles();
    @endphp

    <style>
        .lh-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.875rem;
        }
        @media (min-width: 640px)  { .lh-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; } }
        @media (min-width: 1024px) { .lh-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        @keyframes lhFadeUp { from { opacity: 0; transform: translateY(24px) scale(.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes lhHeader { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes lhFloat {
            0%, 100% { transform: translateY(0) rotate(0); }
            50%      { transform: translateY(-6px) rotate(-3deg); }
        }
        @keyframes lhPulseDot {
            0%   { box-shadow: 0 0 0 0 rgba(239,68,68,.55); }
            70%  { box-shadow: 0 0 0 12px rgba(239,68,68,0); }
            100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); }
        }
        @keyframes lhSparkle {
            0%, 100% { opacity: 0; transform: scale(0.6) rotate(0deg); }
            50%      { opacity: 1; transform: scale(1.1) rotate(180deg); }
        }

        .lh-header { animation: lhHeader .6s ease-out both; }
        .lh-header-sub { animation: lhHeader .6s ease-out .15s both; }

        .lh-tile {
            position: relative;
            overflow: hidden;
            border-radius: 1.25rem;
            animation: lhFadeUp .6s cubic-bezier(.34,1.56,.64,1) backwards;
            transition: transform .35s cubic-bezier(.34,1.56,.64,1),
                        box-shadow .35s ease-out,
                        filter .35s ease-out;
            box-shadow: 0 6px 20px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.20);
            cursor: pointer;
            min-height: 200px;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .lh-tile:nth-child(1) { animation-delay: 0s, 0s; }
        .lh-tile:nth-child(2) { animation-delay: .1s, 0s; }
        .lh-tile:nth-child(3) { animation-delay: .2s, 0s; }

        .lh-tile:hover {
            transform: translateY(-8px) scale(1.025);
            box-shadow: 0 30px 50px -12px rgba(0,0,0,.4),
                        0 12px 24px -8px rgba(0,0,0,.25),
                        inset 0 1px 0 rgba(255,255,255,.30);
            filter: saturate(1.2) brightness(1.07);
        }
        .lh-tile:active {
            transform: translateY(-3px) scale(.99);
            transition-duration: .12s;
        }
        .lh-tile.coming-soon {
            cursor: not-allowed;
        }
        .lh-tile.coming-soon::after {
            content: 'Segera tersedia';
            position: absolute; top: 1rem; left: 50%; transform: translateX(-50%);
            padding: .2rem .65rem; border-radius: 999px;
            background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.35);
            backdrop-filter: blur(6px);
            font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            color: white;
        }

        .lh-icon {
            transition: transform .5s cubic-bezier(.34,1.56,.64,1),
                        background .35s, box-shadow .35s;
            backdrop-filter: blur(10px);
            animation: lhFloat 3.5s ease-in-out infinite;
        }
        .lh-tile:hover .lh-icon {
            transform: scale(1.25) rotate(-10deg);
            background: rgba(255,255,255,.32);
            box-shadow: 0 10px 26px -4px rgba(0,0,0,.3);
            animation-play-state: paused;
        }

        .lh-shine {
            position: absolute; inset: 0;
            background: linear-gradient(115deg, transparent 35%, rgba(255,255,255,.38) 50%, transparent 65%);
            transform: translateX(-130%) skewX(-15deg);
            transition: transform .9s cubic-bezier(.4,0,.2,1);
            pointer-events: none;
        }
        .lh-tile:hover .lh-shine { transform: translateX(130%) skewX(-15deg); }

        .lh-orb-a, .lh-orb-b { position: absolute; border-radius: 50%; pointer-events: none; transition: transform 1s ease-out; }
        .lh-orb-a { right: -3rem; top: -3rem; width: 11rem; height: 11rem; background: rgba(255,255,255,.18); filter: blur(20px); }
        .lh-orb-b { left: -3rem; bottom: -3rem; width: 9rem; height: 9rem; background: rgba(0,0,0,.12); filter: blur(28px); }
        .lh-tile:hover .lh-orb-a { transform: translate(-18px, 18px) scale(1.25); }
        .lh-tile:hover .lh-orb-b { transform: translate(18px, -18px) scale(1.3); }

        .lh-sparkle { position: absolute; width: 5px; height: 5px; border-radius: 50%; background: white; opacity: 0; pointer-events: none; }
        .lh-tile:hover .lh-sparkle { animation: lhSparkle 1.6s ease-in-out infinite; }
        .lh-sparkle.s1 { top: 22%; left: 18%; }
        .lh-sparkle.s2 { top: 65%; left: 78%; animation-delay: .25s !important; }
        .lh-sparkle.s3 { top: 30%; right: 14%; animation-delay: .5s !important; }
        .lh-sparkle.s4 { bottom: 28%; left: 28%; animation-delay: .75s !important; }
        .lh-sparkle.s5 { top: 50%; left: 52%; animation-delay: 1s !important; }

        .lh-badge {
            position: absolute; top: 1rem; right: 1rem;
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 26px; height: 26px; padding: 0 .55rem;
            border-radius: 999px; font-size: .75rem; font-weight: 800; color: white;
            background: rgba(15,23,42,.6);
            border: 1px solid rgba(255,255,255,.35);
            backdrop-filter: blur(6px);
            animation: lhFadeUp .6s cubic-bezier(.34,1.56,.64,1) .5s backwards;
        }
        .lh-badge.urgent {
            background: #ef4444;
            animation: lhFadeUp .6s cubic-bezier(.34,1.56,.64,1) .5s backwards,
                       lhPulseDot 2s ease-out 1.2s infinite;
        }

        .lh-action {
            position: absolute; left: 0; right: 0; bottom: 0;
            padding: .65rem 1rem;
            background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,.45) 100%);
            transform: translateY(110%);
            transition: transform .35s cubic-bezier(.34,1.56,.64,1);
            display: flex; align-items: center; justify-content: space-between;
            color: white; font-size: .8rem; font-weight: 600;
            pointer-events: none;
        }
        .lh-tile:hover .lh-action { transform: translateY(0); }

        @media (prefers-reduced-motion: reduce) {
            .lh-tile, .lh-icon, .lh-shine, .lh-orb-a, .lh-orb-b, .lh-sparkle, .lh-badge, .lh-header, .lh-header-sub {
                animation: none !important; transition: none !important;
            }
        }
    </style>

    {{-- ── Header ─────────────────────────────────────────────── --}}
    <div class="mb-6 md:mb-8">
        <div class="lh-header text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
            <span>›</span>
            <span>Launching</span>
        </div>
        <h1 class="lh-header text-2xl md:text-4xl font-bold mt-1" style="color:#ff8c00;">
            🎉 Launching Center
        </h1>
        <p class="lh-header-sub text-sm text-gray-500 dark:text-gray-400 mt-1">
            Aktifkan event, buka peta unit live, dan tutup NUP — semuanya di sini.
        </p>
    </div>

    {{-- ── Sub-tile grid (3 tiles) ──────────────────────────── --}}
    <div class="lh-grid">
        @foreach ($tiles as $t)
            @php
                $count = $t['count'] instanceof \Closure ? ($t['count'])() : $t['count'];
                $hasCount = is_numeric($count) && (int) $count > 0;
                $urgent = $hasCount && \Illuminate\Support\Str::slug($t['label']) === 'closing-nup';
                $comingSoon = $t['url'] === '#';
            @endphp
            <a href="{{ $t['url'] }}"
               class="lh-tile {{ $comingSoon ? 'coming-soon' : '' }}"
               style="background: {{ $t['gradient'] }};"
               @if ($comingSoon) onclick="event.preventDefault();" aria-disabled="true" @endif>

                <div class="lh-orb-a"></div>
                <div class="lh-orb-b"></div>
                <div class="lh-shine"></div>

                <span class="lh-sparkle s1"></span>
                <span class="lh-sparkle s2"></span>
                <span class="lh-sparkle s3"></span>
                <span class="lh-sparkle s4"></span>
                <span class="lh-sparkle s5"></span>

                @if ($hasCount)
                    <span class="lh-badge {{ $urgent ? 'urgent' : '' }}">
                        {{ $count > 99 ? '99+' : $count }}
                        <span style="font-weight:500; margin-left:4px; opacity:.85; font-size:.65rem;">{{ $t['count_label'] }}</span>
                    </span>
                @endif

                <div class="relative h-full w-full flex flex-col items-center justify-center px-5 py-8 text-center" style="min-height:200px;">
                    <span class="lh-icon inline-grid place-items-center rounded-2xl mb-4"
                          style="width:72px; height:72px; background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.40);">
                        <svg class="w-10 h-10 text-white drop-shadow-lg" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $t['icon'] }}"/>
                        </svg>
                    </span>

                    <div class="text-xl md:text-2xl font-bold text-white drop-shadow leading-tight">
                        {{ $t['label'] }}
                    </div>
                    <div class="text-xs md:text-sm text-white/85 mt-2 max-w-xs leading-snug">
                        {{ $t['sub'] }}
                    </div>
                </div>

                @unless ($comingSoon)
                    <div class="lh-action">
                        <span>Buka</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                @endunless
            </a>
        @endforeach
    </div>

</x-filament-panels::page>
