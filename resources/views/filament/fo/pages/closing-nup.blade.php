<x-filament-panels::page>
    <div class="mb-4">
        <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
            <span>›</span>
            <a href="{{ url('/fo/launching') }}" class="hover:underline" style="color:#60a5fa;">Launching</a>
            <span>›</span>
            <span>Closing NUP</span>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Naik kontrak dari NUP yang sudah <strong>Pilih Unit</strong>. Pilih skema cara bayar + sales,
            lalu <em>Closing</em> akan membuat <strong>Kontrak</strong> + <strong>TTS</strong> (UTJ) yang siap dibuka jadi kwitansi.
        </p>
    </div>

    {{ $this->table }}

    {{-- Hasil closing: kwitansi, SPR & jadwal dalam satu jendela (tak perlu pindah modul). --}}
    <x-filament::modal id="hasil-closing" width="lg" :close-by-clicking-away="false">
        <x-slot name="heading">Closing Berhasil</x-slot>

        @if ($hasil)
            <div class="space-y-1 text-sm">
                <div><span class="text-gray-500">Customer:</span> <strong>{{ $hasil['customer'] }}</strong></div>
                <div><span class="text-gray-500">Unit:</span> <strong>{{ $hasil['unit'] }}</strong></div>
                <div><span class="text-gray-500">Kontrak (SPR):</span> <strong>{{ $hasil['no_kontrak'] }}</strong></div>
                <div><span class="text-gray-500">TTS UTJ:</span> {{ $hasil['no_tts'] }}</div>
                @if ($hasil['gimmick'])
                    <div><span class="text-gray-500">Gimmick:</span> {{ $hasil['gimmick'] }} item</div>
                @endif
                @if ($hasil['tambah_dp'])
                    <div><span class="text-gray-500">Tambah DP:</span> {{ $hasil['tambah_dp'] }} baris</div>
                @endif
            </div>

            <div class="grid gap-2 mt-4">
                @if ($hasil['bkm_id'])
                    <x-filament::button tag="a" target="_blank" icon="heroicon-o-printer" color="success"
                        :href="route('fo.cetak.kwitansi', $hasil['bkm_id'])">
                        Cetak Kwitansi {{ $hasil['no_bkm'] }}
                    </x-filament::button>
                @else
                    <x-filament::button wire:click="bukaKwitansiHasil" icon="heroicon-o-receipt-percent" color="success">
                        Buka Kwitansi UTJ
                    </x-filament::button>
                @endif
                <x-filament::button tag="a" target="_blank" icon="heroicon-o-document-text"
                    :href="route('fo.cetak.spr', $hasil['kontrak_id'])">
                    Cetak SPR
                </x-filament::button>
                <x-filament::button tag="a" target="_blank" icon="heroicon-o-calendar-days" color="gray"
                    :href="route('fo.cetak.jadwal', $hasil['kontrak_id'])">
                    Jadwal Pembayaran
                </x-filament::button>
            </div>
        @endif

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'hasil-closing' })">Tutup</x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
