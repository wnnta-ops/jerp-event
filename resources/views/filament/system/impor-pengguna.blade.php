{{-- Form upload BIASA (multipart) — bukan FileUpload Livewire, yang di server kehilangan
     berkasnya saat dikirim. Lihat App\Http\Controllers\ImporPenggunaController. --}}
<form method="POST" action="{{ route('system.pengguna.impor') }}" enctype="multipart/form-data"
      x-data="{ nama: '', kirim: false }" @submit="kirim = true" class="space-y-4">
    @csrf

    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-gray-300 px-4 py-4 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5">
        <x-heroicon-o-document-arrow-up class="h-6 w-6 text-gray-400" />
        <span x-text="nama || 'Klik untuk memilih berkas Excel (.xlsx)…'" :class="nama ? 'font-medium' : 'text-gray-500'"></span>
        <input type="file" name="berkas" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="hidden"
               @change="nama = $event.target.files[0]?.name ?? ''">
    </label>

    <p class="text-xs text-gray-500">
        Bila ada sel yang kosong atau salah, tidak ada yang disimpan dan muncul daftar <b>baris · kolom · kesalahannya</b>.
    </p>

    <div class="flex justify-end">
        <button type="submit" :disabled="!nama || kirim"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50">
            <span x-show="!kirim">Import</span>
            <span x-show="kirim" x-cloak>Mengimpor… (jangan tutup halaman)</span>
        </button>
    </div>
</form>
