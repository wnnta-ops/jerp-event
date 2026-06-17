<x-filament-panels::page>
    <style>
        @keyframes ar-rise { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:translateY(0) } }
        @keyframes ar-dash { to { stroke-dashoffset:0 } }
        @keyframes ar-fade { from { opacity:0 } to { opacity:1 } }
        @keyframes ar-seg { from { stroke-dasharray:0 100 } to { stroke-dasharray:var(--seg) 100 } }
        .ar-rise { opacity:0; animation:ar-rise .55s cubic-bezier(.22,1,.36,1) forwards }
        .ar-card { transition:box-shadow .25s, transform .25s, border-color .25s }
        .ar-card:hover { box-shadow:0 10px 30px -16px rgba(2,6,23,.35); transform:translateY(-2px) }
        .ar-path { stroke-dasharray:var(--len); stroke-dashoffset:var(--len); animation:ar-dash 1.5s cubic-bezier(.22,1,.36,1) .2s forwards }
        .ar-seg { stroke-dasharray:0 100; animation:ar-seg 1s cubic-bezier(.22,1,.36,1) both }
        .ar-row { transition:background-color .2s }
        .ar-row:hover { background:rgb(99 102 241 / .06) }
    </style>

    @php
        $countup = fn (int $n, int $delay = 0) =>
            'x-data="{n:0}" x-init="let t='.$n.',s=null,d=950;function f(ts){if(!s)s=ts;let p=Math.min(1,(ts-s)/d);n=Math.round(t*(1-Math.pow(1-p,3)));if(p<1)requestAnimationFrame(f)}setTimeout(()=>requestAnimationFrame(f),'.$delay.')" x-text="new Intl.NumberFormat(\'id-ID\').format(n)"';
        $accent = function (string $grad): string {
            preg_match('/#[0-9a-fA-F]{6}/', $grad, $mm);
            return $mm[0] ?? '#6366f1';
        };

        $coll = $this->getCollection();
        $vals = array_map(fn ($c) => (float) $c['value'], $coll);
        $maxC = max(1, max($vals));
        $lastV = end($vals); $prevV = count($vals) >= 2 ? $vals[count($vals) - 2] : 0;
        $trend = $prevV > 0 ? round(($lastV - $prevV) / $prevV * 100) : ($lastV > 0 ? 100 : 0);

        $W = 720; $H = 190; $padB = 22; $padT = 14; $nC = count($coll);
        $stepX = $nC > 1 ? $W / ($nC - 1) : $W;
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = [round($i * $stepX, 1), round($padT + (1 - $v / $maxC) * ($H - $padT - $padB), 1)];
        }
        $line = '';
        foreach ($pts as $i => $p) { $line .= ($i === 0 ? 'M' : 'L').$p[0].','.$p[1].' '; }
        $area = $line.'L'.$pts[$nC - 1][0].','.($H - $padB).' L'.$pts[0][0].','.($H - $padB).' Z';

        $inst = $this->getInstrumen();
        $totalI = array_sum(array_map(fn ($i) => (int) $i['count'], $inst));
    @endphp

    {{-- ── KPI (kartu bersih) ── --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->getMetrics() as $idx => $m)
            @php $c = $accent($m['grad']); $isColl = str_contains($m['label'], 'Collection'); @endphp
            <div class="ar-rise ar-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900" style="animation-delay: {{ $idx * 70 }}ms;">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $m['label'] }}</span>
                    <span class="grid h-9 w-9 place-items-center rounded-xl" style="background: {{ $c }}1a;">
                        <x-filament::icon :icon="$m['icon']" class="h-5 w-5" style="color: {{ $c }};" />
                    </span>
                </div>
                <div class="mt-3 text-[1.7rem] font-extrabold leading-none tracking-tight text-gray-900 tabular-nums dark:text-white">{{ $m['value'] }}</div>
                <div class="mt-2 flex items-center gap-2">
                    @if ($isColl)
                        <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-bold"
                              style="color: {{ $trend >= 0 ? '#059669' : '#e11d48' }}; background: {{ $trend >= 0 ? 'rgb(16 185 129 / .12)' : 'rgb(244 63 94 / .12)' }};">
                            <x-filament::icon :icon="$trend >= 0 ? 'heroicon-s-arrow-up-right' : 'heroicon-s-arrow-down-right'" class="h-3 w-3" />{{ abs($trend) }}%
                        </span>
                        <span class="text-[11px] text-gray-400">vs bln lalu</span>
                    @else
                        <span class="text-[11px] font-medium text-gray-400">{{ $m['sub'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- ── Grafik area Collection ── --}}
        <div class="ar-rise ar-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900 lg:col-span-2" style="animation-delay: 180ms;">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">Collection 6 Bulan Terakhir</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Total kas masuk (BKM) per bulan</div>
                </div>
                <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-bold"
                      style="color: {{ $trend >= 0 ? '#059669' : '#e11d48' }}; background: {{ $trend >= 0 ? 'rgb(16 185 129 / .12)' : 'rgb(244 63 94 / .12)' }};">
                    <x-filament::icon :icon="$trend >= 0 ? 'heroicon-s-arrow-up-right' : 'heroicon-s-arrow-down-right'" class="h-3.5 w-3.5" />{{ abs($trend) }}%
                </span>
            </div>

            <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" class="mt-4 w-full" style="height:190px;">
                <defs>
                    <linearGradient id="arFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#10b981" stop-opacity=".25" />
                        <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                    </linearGradient>
                </defs>
                @for ($g = 0; $g <= 3; $g++)
                    @php $gy = $padT + $g * (($H - $padT - $padB) / 3); @endphp
                    <line x1="0" x2="{{ $W }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="currentColor" class="text-gray-200 dark:text-white/10" stroke-width="1" />
                @endfor
                <path d="{{ $area }}" fill="url(#arFill)" style="animation: ar-fade 1.1s ease .4s both;" />
                <path d="{{ $line }}" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="ar-path" style="--len: {{ $W * 1.6 }};" />
                @foreach ($pts as $i => $p)
                    <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="{{ $i === $nC - 1 ? 4.5 : 3 }}" fill="#fff" stroke="#10b981" stroke-width="2" style="animation: ar-fade .4s ease {{ 0.55 + $i * 0.1 }}s both;" />
                @endforeach
            </svg>
            <div class="mt-1 flex justify-between px-1">
                @foreach ($coll as $c)
                    <div class="flex-1 text-center">
                        <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $c['label'] }}</div>
                        @if ($c['value'] > 0)<div class="text-[11px] font-bold text-emerald-600">{{ number_format($c['value'] / 1_000_000, 1) }} Jt</div>@endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Donut komposisi instrumen ── --}}
        <div class="ar-rise ar-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900" style="animation-delay: 250ms;">
            <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Komposisi Instrumen AR</div>
            <div class="flex items-center justify-center">
                <div class="relative" style="width:140px;height:140px;">
                    <svg viewBox="0 0 42 42" class="h-full w-full" style="transform: rotate(-90deg);">
                        <circle cx="21" cy="21" r="15.915" fill="none" stroke="currentColor" class="text-gray-100 dark:text-white/10" stroke-width="5" />
                        @php $startSeg = 0; @endphp
                        @foreach ($inst as $k => $i)
                            @php $pct = $totalI > 0 ? round((int) $i['count'] / $totalI * 100, 2) : 0; @endphp
                            @if ($pct > 0)
                                <circle cx="21" cy="21" r="15.915" fill="none" stroke="{{ $i['color'] }}" stroke-width="5"
                                        class="ar-seg" style="--seg: {{ $pct }}; stroke-dashoffset: {{ -$startSeg }}; animation-delay: {{ 300 + $k * 80 }}ms;" />
                                @php $startSeg += $pct; @endphp
                            @endif
                        @endforeach
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-2xl font-extrabold text-gray-900 tabular-nums dark:text-white" {!! $countup($totalI, 350) !!}></span>
                        <span class="text-[10px] font-medium uppercase tracking-wide text-gray-400">Total</span>
                    </div>
                </div>
            </div>
            <div class="mt-4 space-y-0.5">
                @foreach ($inst as $k => $i)
                    <a href="{{ $i['url'] }}" class="ar-row flex items-center justify-between rounded-lg px-2 py-1.5 text-xs">
                        <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                            <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $i['color'] }};"></span>{{ $i['label'] }}
                        </span>
                        <span class="font-bold text-gray-900 tabular-nums dark:text-white" {!! $countup((int) $i['count'], 380 + $k * 50) !!}></span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Perlu tindak lanjut ── --}}
    <div class="ar-rise" style="animation-delay: 320ms;">
        <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Perlu Tindak Lanjut</div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach ($this->getTindakLanjut() as $n => $a)
                <a href="{{ $a['url'] }}"
                   class="ar-card group rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                   style="animation: ar-rise .5s cubic-bezier(.22,1,.36,1) both; animation-delay: {{ 360 + $n * 60 }}ms;">
                    <div class="flex items-center justify-between">
                        <span class="grid h-9 w-9 place-items-center rounded-xl transition group-hover:scale-110" style="background: {{ $a['color'] }}1a;">
                            <x-filament::icon :icon="$a['icon']" class="h-5 w-5" style="color: {{ $a['color'] }};" />
                        </span>
                        <span class="text-2xl font-extrabold tabular-nums" style="color: {{ $a['count'] > 0 ? $a['color'] : '#9ca3af' }};" {!! $countup((int) $a['count'], 420 + $n * 60) !!}></span>
                    </div>
                    <div class="mt-3 text-xs font-semibold leading-tight text-gray-700 dark:text-gray-200">{{ $a['label'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
