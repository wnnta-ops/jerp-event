<script setup lang="ts">
/**
 * Settings → Numerator Back Office.
 *
 * Terpisah dari layar Numerator (Front Office): keduanya dua daftar berbeda — dokumen
 * penjualan & legal di sana, dokumen akuntansi di sini. Dicampur, operator harus memilah
 * 52 baris untuk menemukan satu.
 */
import { ref, reactive, computed } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, Hash, Plus, Pencil, Eye, Trash2, X, TriangleAlert } from 'lucide-vue-next';

type Row = {
    id: number; code: string; prefix: string; format: string;
    seq_padding: number; reset_strategy: string; current_period: string | null;
    current_seq: number; label: string | null; grup: string; bawaan: boolean;
    catatan: string | null;
};

const props = defineProps<{
    rows: Row[];
    belumAda: Array<{ code: string; label: string; grup: string }>;
    resets: Record<string, string>;
    tokens: Record<string, string>;
}>();

// Dikelompokkan per modul supaya 35 baris tidak terbaca sebagai satu daftar panjang.
const grup = computed(() => {
    const out: Record<string, Row[]> = {};
    for (const r of props.rows) (out[r.grup] ??= []).push(r);

    return Object.entries(out).sort(([a], [b]) => a.localeCompare(b));
});

const previews = reactive<Record<number, string>>({});
async function lihat(r: Row) {
    const res = await axios.get(`/settings/bo-numerators/${encodeURIComponent(r.code)}/preview`, { withCredentials: true });
    previews[r.id] = res.data.preview;
}

// ── Tambah & Edit ───────────────────────────────────────────────────────────
const buka = ref(false);
const sedangEdit = ref<Row | null>(null);
const form = useForm({
    code: '', prefix: '', format: '{PREFIX}/{YY}{MM}/{SEQ}',
    seq_padding: 4, reset_strategy: 'yearly', current_seq: 0,
});

function tambah() {
    sedangEdit.value = null;
    form.reset();
    form.clearErrors();
    buka.value = true;
}
function edit(r: Row) {
    sedangEdit.value = r;
    form.clearErrors();
    form.code = r.code;
    form.prefix = r.prefix;
    form.format = r.format;
    form.seq_padding = r.seq_padding;
    form.reset_strategy = r.reset_strategy;
    form.current_seq = r.current_seq;
    buka.value = true;
}
function simpan() {
    const selesai = { onSuccess: () => { buka.value = false; } };
    if (sedangEdit.value) form.put(route('settings.bo-numerators.update', sedangEdit.value.id), selesai);
    else form.post(route('settings.bo-numerators.store'), selesai);
}
function hapus(r: Row) {
    if (confirm(`Hapus numerator ${r.code}? Dokumennya akan kembali memakai format bawaan di kode.`)) {
        router.delete(route('settings.bo-numerators.destroy', r.id), { preserveScroll: true });
    }
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('settings.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Settings</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Hash class="h-6 w-6 text-primary-600" /> Numerator Back Office</h1>
                <p class="text-sm text-neutral-500">Format penomoran seluruh dokumen Back Office — jurnal, pembelian, hutang, piutang, kas &amp; bank, kontraktor, aset, COGS, dan budget.</p>
            </div>
            <button type="button" @click="tambah"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                <Plus class="h-4 w-4" /> Tambah
            </button>
        </div>

        <!-- Apa yang sebenarnya terjadi saat format diubah, dikatakan di depan. -->
        <div class="card p-4 text-[12px] leading-relaxed text-neutral-600 dark:text-neutral-300">
            <p>
                Format di sini <b>benar-benar dipakai</b> saat dokumen diterbitkan. Nomor urutnya dikunci per baris,
                jadi dua penyimpanan bersamaan tidak lagi bisa memperoleh nomor yang sama.
            </p>
            <p class="mt-1.5">
                Segmen tanggal (<code>{YY}</code>, <code>{MM}</code>, …) diambil dari <b>tanggal dokumen</b>, bukan
                tanggal hari ini — dokumen bertanggal mundur tetap memakai bulannya sendiri. Yang memakai tanggal hari
                ini hanyalah penentuan kapan nomor urut kembali ke 1.
            </p>
            <p class="mt-1.5">
                <b>Nomor urut tidak bisa diturunkan.</b> Menurunkannya berarti menerbitkan ulang nomor yang sudah
                dipakai, dan hampir semua kolom nomor dokumen bersifat unik — dokumennya akan gagal disimpan.
            </p>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <span v-for="(ket, t) in tokens" :key="t" class="whitespace-nowrap">
                    <code class="rounded bg-neutral-100 px-1 font-mono text-[11px] dark:bg-neutral-800">{{ t }}</code>
                    <span class="ml-1 text-neutral-400">{{ ket }}</span>
                </span>
            </div>
        </div>

        <!-- Dokumen katalog yang barisnya belum ada: dikatakan, bukan didiamkan. -->
        <div v-if="belumAda.length" class="card flex items-start gap-2 border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <p>
                <b>{{ belumAda.length }} dokumen belum punya baris numerator</b> dan masih memakai format bawaan di kode:
                {{ belumAda.map((b) => b.code).join(', ') }}. Tambahkan agar formatnya bisa diatur dari sini.
            </p>
        </div>

        <div v-for="[nama, isi] in grup" :key="nama" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:border-neutral-800">{{ nama }}</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">Kode</th><th class="px-4 py-2.5">Dokumen</th><th class="px-4 py-2.5">Prefix</th>
                    <th class="px-4 py-2.5">Format</th><th class="px-4 py-2.5 text-center">Digit</th>
                    <th class="px-4 py-2.5">Reset</th><th class="px-4 py-2.5 text-right">No. Terakhir</th>
                    <th class="px-4 py-2.5">Contoh Berikutnya</th><th class="px-4 py-2.5 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in isi" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2.5">
                            {{ r.label ?? '—' }}
                            <span v-if="!r.bawaan" class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800">tambahan</span>
                            <!-- Dikatakan di barisnya sendiri: dokumen yang penghitungnya
                                 tidak di sini akan menyesatkan kalau kolom No. Terakhir-nya
                                 dibaca seolah menentukan nomor berikutnya. -->
                            <span v-if="r.catatan" class="mt-0.5 block text-[10.5px] leading-snug text-amber-600 dark:text-amber-400">{{ r.catatan }}</span>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.prefix }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.format }}</td>
                        <td class="px-4 py-2.5 text-center tabular-nums">{{ r.seq_padding }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ resets[r.reset_strategy] ?? r.reset_strategy }}</td>
                        <td class="px-4 py-2.5 text-right font-mono tabular-nums" :class="r.catatan ? 'text-neutral-400 line-through' : ''">{{ r.current_seq }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-primary-600">
                            <span v-if="previews[r.id]">{{ previews[r.id] }}</span>
                            <button v-else type="button" @click="lihat(r)" class="inline-flex items-center gap-1 text-neutral-400 hover:text-primary-600"><Eye class="h-3.5 w-3.5" /> Lihat</button>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="inline-flex items-center gap-3">
                                <button type="button" @click="edit(r)" class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline"><Pencil class="h-3.5 w-3.5" /> Edit</button>
                                <!-- Dokumen bawaan tidak bisa dihapus: penerbitnya akan jatuh ke
                                     format di kode, dan layar ini berhenti mencerminkan kenyataan. -->
                                <button v-if="!r.bawaan" type="button" @click="hapus(r)" class="inline-flex items-center gap-1 text-xs text-danger-500 hover:underline"><Trash2 class="h-3.5 w-3.5" /> Hapus</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tambah / Edit -->
        <div v-if="buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="buka = false">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ sedangEdit ? 'Edit' : 'Tambah' }} Numerator Back Office</h2>
                    <button type="button" @click="buka = false" class="text-neutral-400 hover:text-neutral-700"><X class="h-5 w-5" /></button>
                </div>

                <form class="space-y-3" @submit.prevent="simpan">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kode</label>
                        <!-- Kode dikunci saat mengubah: ia kunci yang dipakai kode penerbit
                             dokumen; menggantinya membuat dokumennya kehilangan masternya. -->
                        <input v-model="form.code" :disabled="!!sedangEdit" :class="[inputCls, sedangEdit ? 'opacity-60' : '']" placeholder="mis. BKM" />
                        <p v-if="sedangEdit" class="mt-1 text-[11px] text-neutral-400">Kode tidak bisa diubah — ia yang menghubungkan baris ini ke dokumennya.</p>
                        <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Prefix</label>
                        <input v-model="form.prefix" :class="inputCls" placeholder="mis. BKM" />
                        <p v-if="form.errors.prefix" class="mt-1 text-xs text-danger-500">{{ form.errors.prefix }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Format</label>
                        <input v-model="form.format" :class="[inputCls, 'font-mono text-xs']" />
                        <p v-if="form.errors.format" class="mt-1 text-xs text-danger-500">{{ form.errors.format }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Jumlah Digit</label>
                            <input v-model.number="form.seq_padding" type="number" min="1" max="10" :class="inputCls" />
                            <p v-if="form.errors.seq_padding" class="mt-1 text-xs text-danger-500">{{ form.errors.seq_padding }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Reset Nomor</label>
                            <select v-model="form.reset_strategy" :class="inputCls">
                                <option v-for="(nama, k) in resets" :key="k" :value="k">{{ nama }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Nomor Terakhir Terpakai</label>
                        <input v-model.number="form.current_seq" type="number" min="0" :class="inputCls" />
                        <p class="mt-1 text-[11px] text-neutral-400">Hanya boleh dinaikkan. Menurunkannya akan menerbitkan ulang nomor yang sudah dipakai.</p>
                        <p v-if="form.errors.current_seq" class="mt-1 text-xs text-danger-500">{{ form.errors.current_seq }}</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="buka = false" class="h-9 rounded-lg border border-neutral-300 px-4 text-sm font-medium dark:border-neutral-700">Batal</button>
                        <button type="submit" :disabled="form.processing"
                                class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                            {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
