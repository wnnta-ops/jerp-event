<script setup lang="ts">
/**
 * Drop box unggah berkas tagihan (seperti Google Drive): seret / pilih banyak file, lalu tiap file
 * diberi jenis — Invoice, Kuitansi, Faktur Pajak, atau Lainnya (wajib diisi dokumen apa).
 * Sama dengan halaman unggah supplier (public/berkas-supplier.blade.php). Jenis ditebak dari nama
 * file dan bisa diganti; chip di atas daftar menandai jenis yang sudah ada.
 */
import { computed, ref } from 'vue';
import { UploadCloud, X } from 'lucide-vue-next';

export type FileBerkas = { file: File; berkas_id: number | ''; keterangan: string };
type Jenis = { id: number; code?: string; name: string; is_mandatory: boolean };

const props = withDefaults(defineProps<{ modelValue: FileBerkas[]; jenis: Jenis[]; maks?: number }>(), { maks: 10 });
const emit = defineEmits<{ (e: 'update:modelValue', v: FileBerkas[]): void }>();

const MAKS_UKURAN = 10 * 1024 * 1024;
const POLA: Record<string, RegExp> = {
    INVOICE: /invoice|inv|nota|tagihan/i, KUITANSI: /kuitansi|kwitansi|receipt/i, 'FAKTUR-PAJAK': /faktur|efaktur|pajak|tax/i,
};
const idLainnya = computed(() => props.jenis.find((j) => j.code === 'LAINNYA')?.id ?? null);
const input = ref<HTMLInputElement | null>(null);
const aktif = ref(false);
const galat = ref('');

function tebak(nama: string): number | '' {
    for (const j of props.jenis) if (j.code && POLA[j.code]?.test(nama)) return j.id;
    return '';
}
function tambah(files: FileList | null) {
    galat.value = '';
    const daftar = [...props.modelValue];
    for (const f of Array.from(files ?? [])) {
        if (daftar.length >= props.maks) { galat.value = `Maksimal ${props.maks} file.`; break; }
        if (!/\.(pdf|jpe?g|png)$/i.test(f.name)) { galat.value = `"${f.name}" bukan PDF/JPG/PNG.`; continue; }
        if (f.size > MAKS_UKURAN) { galat.value = `"${f.name}" lebih dari 10 MB.`; continue; }
        daftar.push({ file: f, berkas_id: tebak(f.name), keterangan: '' });
    }
    emit('update:modelValue', daftar);
}
function ubah(i: number, isi: Partial<FileBerkas>) {
    emit('update:modelValue', props.modelValue.map((b, k) => (k === i ? { ...b, ...isi } : b)));
}
function hapus(i: number) {
    emit('update:modelValue', props.modelValue.filter((_, k) => k !== i));
}
function lepas(e: DragEvent) {
    aktif.value = false;
    tambah(e.dataTransfer?.files ?? null);
}
const ada = computed(() => new Set(props.modelValue.map((b) => b.berkas_id)));
const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
const ext = (n: string) => n.split('.').pop()?.toUpperCase() ?? '';
</script>

<template>
    <div>
        <div role="button" tabindex="0"
            :class="['flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed px-4 py-6 text-center transition',
                aktif ? 'border-primary-500 bg-primary-50/60 dark:bg-primary-900/20' : 'border-neutral-300 hover:border-primary-400 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800/50']"
            @click="input?.click()" @keydown.enter.prevent="input?.click()" @keydown.space.prevent="input?.click()"
            @dragenter.prevent="aktif = true" @dragover.prevent="aktif = true" @dragleave.prevent="aktif = false" @drop.prevent="lepas">
            <UploadCloud class="h-8 w-8 text-primary-600" />
            <span class="mt-1 text-sm font-semibold">Seret file ke sini</span>
            <span class="text-xs text-neutral-500">atau klik untuk memilih (bisa beberapa sekaligus) · PDF/JPG/PNG · maks. 10 MB per file</span>
        </div>
        <input ref="input" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple class="hidden" @change="tambah(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''" />

        <div class="mt-3 flex flex-wrap gap-1.5">
            <span v-for="j in jenis" :key="j.id"
                :class="['rounded-full border px-2.5 py-0.5 text-[11px]', ada.has(j.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-neutral-300 text-neutral-500 dark:border-neutral-700']">
                {{ j.name }}{{ j.is_mandatory ? ' · wajib' : '' }}</span>
        </div>
        <p v-if="galat" class="mt-2 text-xs text-danger-500">{{ galat }}</p>

        <ul v-if="modelValue.length" class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
            <li v-for="(b, i) in modelValue" :key="i" class="grid grid-cols-[2.25rem_1fr_auto] items-center gap-x-3 gap-y-2 py-2 sm:grid-cols-[2.25rem_1fr_14rem_auto]">
                <span :class="['grid h-9 w-9 place-items-center rounded-lg text-[10px] font-bold text-white', ext(b.file.name) === 'PDF' ? 'bg-red-600' : 'bg-blue-600']">{{ ext(b.file.name) }}</span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium" :title="b.file.name">{{ b.file.name }}</div>
                    <div class="text-[11px] text-neutral-500">{{ ukuran(b.file.size) }}</div>
                </div>
                <select :value="b.berkas_id" class="col-span-2 col-start-2 h-9 rounded-lg border border-neutral-300 bg-transparent px-2 text-sm sm:col-span-1 sm:col-start-auto dark:border-neutral-700"
                    :class="!b.berkas_id ? 'border-danger-400' : ''" @change="ubah(i, { berkas_id: Number(($event.target as HTMLSelectElement).value) || '' })">
                    <option value="">— jenis dokumen —</option>
                    <option v-for="j in jenis" :key="j.id" :value="j.id">{{ j.name }}</option>
                </select>
                <button type="button" class="row-start-1 col-start-3 text-neutral-400 hover:text-danger-500 sm:col-start-4" title="Hapus file" @click="hapus(i)"><X class="h-4 w-4" /></button>
                <input v-if="b.berkas_id && b.berkas_id === idLainnya" :value="b.keterangan" maxlength="100" placeholder="Dokumen apa? mis. Surat Kuasa, Berita Acara"
                    class="col-span-2 col-start-2 h-9 rounded-lg border bg-transparent px-2 text-sm sm:col-span-3"
                    :class="b.keterangan.trim() ? 'border-neutral-300 dark:border-neutral-700' : 'border-danger-400'"
                    @input="ubah(i, { keterangan: ($event.target as HTMLInputElement).value })" />
            </li>
        </ul>
    </div>
</template>
