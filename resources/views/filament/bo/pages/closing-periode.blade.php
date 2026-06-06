<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Tutup / Buka Periode</x-slot>
        {{ $this->form }}
        <div class="mt-4 flex gap-3">
            <x-filament::button wire:click="close" color="danger" icon="heroicon-o-lock-closed">
                Tutup Periode
            </x-filament::button>
            <x-filament::button wire:click="reopen" color="gray" icon="heroicon-o-lock-open">
                Buka Kembali
            </x-filament::button>
        </div>
        <p class="mt-3 text-sm text-gray-500">
            Periode hanya bisa ditutup bila semua jurnal sudah POSTED. Periode tertutup menolak posting/perubahan jurnal bertanggal di dalamnya.
        </p>
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">Riwayat Periode</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left"><th class="py-2">Periode</th><th>Status</th><th>Ditutup</th></tr></thead>
            <tbody>
                @forelse ($this->recentPeriods() as $p)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1 font-mono">{{ $p->periode }}</td>
                        <td>
                            <span class="rounded px-2 py-0.5 text-xs {{ $p->status === 'CLOSED' ? 'bg-danger-100 text-danger-700' : 'bg-success-100 text-success-700' }}">
                                {{ $p->status }}
                            </span>
                        </td>
                        <td>{{ $p->closed_at?->format('d M Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-2 text-gray-500">Belum ada periode ditutup.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
