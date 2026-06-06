<x-filament-panels::page>
    {{ $this->form }}

    @php($lr = $this->labaRugi())
    @php($fmt = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'))

    <x-filament::section class="mt-4">
        <x-slot name="heading">Laba Rugi</x-slot>
        <dl class="space-y-2 max-w-xl">
            <div class="flex justify-between"><dt>Pendapatan</dt><dd class="font-semibold text-success-600">{{ $fmt($lr['pendapatan']) }}</dd></div>
            <div class="flex justify-between"><dt>Beban</dt><dd class="font-semibold text-danger-600">({{ $fmt($lr['beban']) }})</dd></div>
            <div class="flex justify-between border-t pt-2"><dt class="font-bold">Laba Bersih</dt>
                <dd class="font-bold {{ $lr['laba'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $fmt($lr['laba']) }}</dd></div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
