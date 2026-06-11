<x-filament-panels::page>
    {{-- ── Metrik utama (hero) ── --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->getMetrics() as $m)
            <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-lg" style="background: {{ $m['grad'] }};">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider opacity-80">{{ $m['label'] }}</div>
                        <div class="mt-2 text-2xl font-extrabold tabular-nums">{{ $m['value'] }}</div>
                        <div class="mt-1 text-[11px] opacity-75">{{ $m['sub'] }}</div>
                    </div>
                    <div class="rounded-xl bg-white/20 p-2">
                        <x-filament::icon :icon="$m['icon']" class="h-6 w-6" />
                    </div>
                </div>
                <div class="pointer-events-none absolute -bottom-6 -right-6 h-24 w-24 rounded-full bg-white/10"></div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- ── Grafik collection 6 bulan ── --}}
        @php $coll = $this->getCollection(); $maxC = max(1, max(array_column($coll, 'value'))); @endphp
        <div class="rounded-2xl border p-5 lg:col-span-2" style="border-color: rgb(148 163 184 / .2);">
            <div class="mb-1 text-sm font-semibold">Collection 6 Bulan Terakhir</div>
            <div class="mb-4 text-xs text-gray-500">Total kas masuk (BKM) per bulan</div>
            <div class="flex items-end justify-between gap-3" style="height:180px;">
                @foreach ($coll as $c)
                    <div class="flex flex-1 flex-col items-center justify-end gap-2" style="height:100%;">
                        <div class="text-[10px] font-semibold text-gray-400">
                            @if ($c['value'] > 0){{ number_format($c['value'] / 1_000_000, 0) }}Jt @endif
                        </div>
                        <div class="w-full rounded-t-lg transition-all"
                            style="height: {{ max(2, round($c['value'] / $maxC * 100)) }}%; background: linear-gradient(180deg,#34d399,#059669); min-height:4px;"
                            title="Rp {{ number_format($c['value'], 0, ',', '.') }}"></div>
                        <div class="text-xs font-medium text-gray-500">{{ $c['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Instrumen AR ── --}}
        @php $inst = $this->getInstrumen(); $maxI = max(1, max(array_column($inst, 'count'))); @endphp
        <div class="rounded-2xl border p-5" style="border-color: rgb(148 163 184 / .2);">
            <div class="mb-4 text-sm font-semibold">Instrumen AR</div>
            <div class="space-y-3">
                @foreach ($inst as $i)
                    <a href="{{ $i['url'] }}" class="block">
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="text-gray-500">{{ $i['label'] }}</span>
                            <span class="font-bold tabular-nums">{{ number_format($i['count']) }}</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full" style="background: rgb(148 163 184 / .15);">
                            <div class="h-full rounded-full" style="width: {{ max(3, round($i['count'] / $maxI * 100)) }}%; background: {{ $i['color'] }};"></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Perlu tindak lanjut ── --}}
    <div>
        <div class="mb-3 text-sm font-semibold">Perlu Tindak Lanjut</div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach ($this->getTindakLanjut() as $a)
                <a href="{{ $a['url'] }}"
                   class="group rounded-xl border p-4 transition hover:shadow-md" style="border-color: rgb(148 163 184 / .25);">
                    <div class="flex items-center justify-between">
                        <div class="grid h-9 w-9 place-items-center rounded-lg" style="background: {{ $a['color'] }}22;">
                            <x-filament::icon :icon="$a['icon']" class="h-5 w-5" style="color: {{ $a['color'] }};" />
                        </div>
                        <span class="text-2xl font-extrabold tabular-nums" style="color: {{ $a['count'] > 0 ? $a['color'] : '#94a3b8' }};">{{ number_format($a['count']) }}</span>
                    </div>
                    <div class="mt-3 text-xs font-semibold leading-tight">{{ $a['label'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
