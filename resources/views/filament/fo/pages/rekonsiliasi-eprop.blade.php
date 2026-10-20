<x-filament-panels::page>
    @php
        $jalan = $this->getJalan();
        $hitung = $this->getHitung();
        $tab = [
            'ditahan' => ['Ditahan', 'warning', 'Ada input J-ERP mirip tapi tidak sama persis — tautkan atau masukkan.'],
            'konflik' => ['Konflik', 'danger', 'Berubah di eProperty, padahal sudah diubah di J-ERP — pilih yang dipakai.'],
            'masuk' => ['Masuk otomatis', 'success', 'Tidak ada di J-ERP → disalin dari eProperty.'],
            'cocok' => ['Cocok', 'info', 'Sudah diinput di J-ERP → ditautkan, tidak disalin.'],
            'diperbarui' => ['Diperbarui', 'primary', 'Dokumen lama yang berubah di eProperty → J-ERP diperbarui.'],
            'hilang' => ['Hilang di eProperty', 'gray', 'Ada di J-ERP tapi tidak ada lagi di eProperty — tidak diubah, hanya laporan.'],
        ];
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-[18rem]">
                <label for="jalanId" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Sinkron</label>
                <select id="jalanId" wire:model.live="jalanId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($this->getDaftarJalan() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($jalan)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Kiriman eProperty <b>{{ $jalan->kiriman ?: '-' }}</b> ·
                    {{ optional($jalan->mulai)->format('d M Y H:i') }} – {{ optional($jalan->selesai)->format('H:i') }}
                    @if ($jalan->kering) · <span class="font-semibold text-amber-600">UJI (tidak menulis)</span> @endif
                    @if ($jalan->status === 'gagal') · <span class="font-semibold text-danger-600">GAGAL — tidak ada yang berubah</span> @endif
                </p>
            @else
                <p class="text-sm text-gray-500">Belum ada sinkron yang dijalankan.</p>
            @endif
        </div>
        @if ($jalan?->status === 'gagal' && $jalan->galat)
            <pre class="mt-3 whitespace-pre-wrap rounded bg-danger-50 p-2 text-xs text-danger-700 dark:bg-danger-500/10">{{ \Illuminate\Support\Str::limit($jalan->galat, 1500) }}</pre>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Keputusan (Tautkan / Masukkan saja / Pakai eProperty / Tetap J-ERP) dijalankan pada sinkron berikutnya.
        </p>
    </div>

    <x-filament::tabs>
        @foreach ($tab as $kode => [$label, $warna])
            <x-filament::tabs.item :active="$aksi === $kode" wire:click="$set('aksi', '{{ $kode }}')" :badge="$hitung[$kode] ?? 0" :badge-color="$warna">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $tab[$aksi][2] ?? '' }}</p>

    {{ $this->table }}
</x-filament-panels::page>
