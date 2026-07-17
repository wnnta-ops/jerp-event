<x-filament-panels::page>
    <style>[x-cloak]{display:none!important}</style>

    @if (session('siteplan_msg'))
        <div class="rounded-lg border border-success-300 bg-success-50 px-4 py-3 text-sm text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
            ✓ {{ session('siteplan_msg') }}
        </div>
    @endif

    {{-- Tombol Edit → modal Ganti/Hapus gambar (upload HTML biasa, bypass FilePond/Livewire). --}}
    <div x-data="{ open: false }">
        <x-filament::button icon="heroicon-o-photo" color="primary" x-on:click="open = true">
            Upload / Ganti Gambar Site Plan
        </x-filament::button>

        <div x-show="open" x-cloak x-transition.opacity
             class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4"
             x-on:click.self="open = false" x-on:keydown.escape.window="open = false">
            <div x-show="open" x-transition
                 class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-1 flex items-start justify-between gap-4">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Upload / Ganti / Hapus Gambar Site Plan</h3>
                    <button type="button" x-on:click="open = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">&times;</button>
                </div>
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                    Pastikan <b>Project &amp; Cluster</b> sudah dipilih pada form di halaman. Pilih gambar lalu <b>Simpan</b> — berlaku untuk <b>menambah gambar baru</b> (cluster belum punya) maupun <b>mengganti</b> yang lama. Atau <b>Hapus</b> untuk mengosongkan.
                </p>

                <form method="POST" action="{{ route('fo.siteplan.image.upload') }}" enctype="multipart/form-data"
                      @submit="$refs.lok1.value = ($wire.$get('data.lokasi_id') ?? ''); if (! $refs.lok1.value) { $event.preventDefault(); window.alert('Pilih Project & Cluster dulu di form halaman.'); }">
                    @csrf
                    <input type="hidden" name="lokasi_id" x-ref="lok1">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Pilih gambar (PNG/JPG, maks 10 MB)</label>
                    <input type="file" name="image" accept="image/*" required
                           class="block w-full text-sm text-gray-700 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-primary-600 file:px-3 file:py-2 file:text-white hover:file:bg-primary-500 dark:text-gray-200">
                    <div class="mt-5">
                        <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" color="primary">Simpan Gambar</x-filament::button>
                    </div>
                </form>

                <hr class="my-5 border-gray-200 dark:border-gray-700">

                <form method="POST" action="{{ route('fo.siteplan.image.destroy') }}"
                      @submit="$refs.lok2.value = ($wire.$get('data.lokasi_id') ?? ''); if (! $refs.lok2.value) { $event.preventDefault(); window.alert('Pilih Cluster dulu.'); return; } if (! window.confirm('Hapus gambar cluster ini? Akan kembali ke fallback gambar project.')) $event.preventDefault();">
                    @csrf @method('DELETE')
                    <input type="hidden" name="lokasi_id" x-ref="lok2">
                    <x-filament::button type="submit" icon="heroicon-o-trash" color="danger" outlined size="sm">Hapus Gambar Cluster</x-filament::button>
                </form>
            </div>
        </div>
    </div>

    <form wire:submit="submit">
        {{ $this->form }}
        <div class="mt-4 flex justify-end gap-3">
            <x-filament::button type="submit" color="gray" icon="heroicon-o-check">
                Simpan Dimensi &amp; Setelan
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
