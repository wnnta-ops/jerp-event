<x-filament-panels::page>
    @if ($selectedKontrakId)
        <div>
            <x-filament::button color="gray" icon="heroicon-o-arrow-left" wire:click="backToList">
                Kembali ke Daftar Kontrak
            </x-filament::button>
        </div>

        <form wire:submit="submit">
            {{ $this->form }}

            <div class="grid gap-4 mt-4 lg:grid-cols-2">
                <x-filament::section>
                    <x-slot name="heading">Jadwal Saat Ini</x-slot>
                    {!! \App\Filament\Fo\Pages\RescheduleTagihan::jadwalHtml($this->jadwalLama) !!}
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Jadwal Baru (Pratinjau)</x-slot>
                    @if ($pratinjauError)
                        <div class="text-sm text-danger-600">{{ $pratinjauError }}</div>
                    @elseif ($pratinjau === null)
                        <div class="text-sm text-gray-500">Pilih skema baru untuk melihat jadwalnya.</div>
                    @else
                        {!! \App\Filament\Fo\Pages\RescheduleTagihan::jadwalHtml($pratinjau) !!}
                        @if (abs($this->selisihPratinjau) >= 1)
                            <div class="mt-3 text-sm text-warning-600">
                                Total termin BF/DP/Angsuran berselisih Rp {{ number_format($this->selisihPratinjau, 0, ',', '.') }}
                                dari Nilai Kontrak — periksa pengaturan skema ini sebelum mengajukan.
                            </div>
                        @endif
                    @endif
                </x-filament::section>
            </div>

            <div class="flex justify-end gap-3 mt-4">
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                    Ajukan Reschedule
                </x-filament::button>
            </div>
        </form>

        <p class="text-xs text-gray-500 mt-2">
            Baris bertipe ADM (Biaya KPR, PPJB, HT, subsidi, …) tidak diubah. Pembayaran yang sudah masuk
            dialokasikan ulang berurutan ke jadwal baru. Nilai Kontrak tetap.
        </p>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
