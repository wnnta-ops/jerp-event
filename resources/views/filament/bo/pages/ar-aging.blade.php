<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
        @foreach ($this->bucketLabels() as $key => $label)
            @php($b = $buckets[$key] ?? ['count' => 0, 'total' => 0])
            <x-filament::section>
                <x-slot name="heading">{{ $label }}</x-slot>
                <div class="text-2xl font-bold">
                    Rp {{ number_format($b['total'], 0, ',', '.') }}
                </div>
                <div class="text-sm text-gray-500">{{ $b['count'] }} invoice</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section class="mt-4">
        <div class="flex items-center justify-between">
            <span class="text-lg font-semibold">Total Piutang Outstanding</span>
            <span class="text-2xl font-bold text-primary-600">
                Rp {{ number_format($total, 0, ',', '.') }}
            </span>
        </div>
    </x-filament::section>
</x-filament-panels::page>
