<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->getCards() as $c)
            <a href="{{ $c['url'] }}"
               class="block rounded-xl border p-5 transition hover:shadow-lg"
               style="border-color: rgb(229 231 235 / .4);">
                <div class="flex items-start justify-between">
                    <div class="grid h-11 w-11 place-items-center rounded-lg" style="background: {{ $c['color'] }}22;">
                        <x-filament::icon :icon="$c['icon']" class="h-6 w-6" style="color: {{ $c['color'] }};" />
                    </div>
                    <span class="text-3xl font-bold tabular-nums" style="color: {{ $c['color'] }};">{{ number_format($c['count']) }}</span>
                </div>
                <h3 class="mt-3 text-sm font-semibold">{{ $c['label'] }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $c['desc'] }}</p>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
