{{-- Upload BIASA (multipart) ke ImporPenggunaController — bukan FileUpload Livewire, yang di
     server kehilangan berkasnya saat dikirim.

     SENGAJA TANPA <form>: isi dialog Action Filament sudah berada di dalam <form> milik
     Filament, dan browser membuang <form> yang bersarang beserta seluruh atributnya
     (x-data, action, method) — Alpine lalu gagal ("nama is not defined") dan tombolnya mati.
     Form-nya dibuat saat tombol ditekan, ditempel di <body> (di luar form Filament), input
     berkasnya dipindah ke sana, lalu dikirim. --}}
<div x-data="{
        nama: '',
        kirim: false,
        kirimkan() {
            if (! this.nama || this.kirim) return;
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = @js(route('system.pengguna.impor'));
            f.enctype = 'multipart/form-data';
            f.style.display = 'none';
            const t = document.createElement('input');
            t.type = 'hidden';
            t.name = '_token';
            t.value = @js(csrf_token());
            f.appendChild(t);
            f.appendChild(this.$refs.berkas);
            document.body.appendChild(f);
            this.kirim = true;
            f.submit();
        },
    }" class="space-y-4">

    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-gray-300 px-4 py-4 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5">
        <x-heroicon-o-document-arrow-up class="h-6 w-6 shrink-0 text-gray-400" />
        <span x-text="nama || 'Klik untuk memilih berkas Excel (.xlsx)…'" :class="nama ? 'font-medium' : 'text-gray-500'"></span>
        <input x-ref="berkas" type="file" name="berkas" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden"
               @change="nama = $event.target.files[0]?.name ?? ''">
    </label>

    <p class="text-xs text-gray-500">
        Bila ada sel yang kosong atau salah, tidak ada yang disimpan dan muncul daftar <b>baris · kolom · kesalahannya</b>.
    </p>

    <div class="flex justify-end">
        <button type="button" @click="kirimkan()" :disabled="! nama || kirim"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50">
            <span x-show="! kirim">Import</span>
            <span x-show="kirim" x-cloak>Mengimpor… (jangan tutup halaman)</span>
        </button>
    </div>
</div>
