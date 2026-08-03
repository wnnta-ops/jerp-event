<!-- Set Up SPK — konfigurasi draft/template dokumen Cetak SPK, per jenis SPK -->
<script setup lang="ts">
import { useForm, router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCog, Plus, Trash2, ArrowUp, ArrowDown, Save, RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

type Pasal = { title: string; body: string; table?: string | null };

const props = defineProps<{
    jenis: string;
    jenisOpts: Record<string, string>;
    draft: {
        judul: string; subjudul: string; proyek_line: string | null; judul_pekerjaan: string | null;
        tempat: string | null; pembukaan: string | null; mukadimah: string | null;
        pihak1_teks: string | null; pihak2_teks: string | null; pasals: Pasal[];
    };
    isDefault: boolean;
    placeholders: Record<string, string>;
    tables: Record<string, string>;
}>();

const form = useForm({
    jenis: props.jenis,
    judul: props.draft.judul ?? '',
    subjudul: props.draft.subjudul ?? '',
    proyek_line: props.draft.proyek_line ?? '',
    judul_pekerjaan: props.draft.judul_pekerjaan ?? '',
    tempat: props.draft.tempat ?? '',
    pembukaan: props.draft.pembukaan ?? '',
    mukadimah: props.draft.mukadimah ?? '',
    pihak1_teks: props.draft.pihak1_teks ?? '',
    pihak2_teks: props.draft.pihak2_teks ?? '',
    pasals: (props.draft.pasals ?? []).map((p) => ({ title: p.title, body: p.body, table: p.table ?? '' })),
});

function switchJenis(j: string) {
    if (j === props.jenis) return;
    router.get(route('bs.spk.draft.edit'), { jenis: j }, { preserveScroll: true });
}
function addPasal() { form.pasals.push({ title: `Pasal ${form.pasals.length + 1} — `, body: '', table: '' }); }
function delPasal(i: number) { form.pasals.splice(i, 1); }
function move(i: number, d: number) {
    const j = i + d;
    if (j < 0 || j >= form.pasals.length) return;
    const tmp = form.pasals[i]; form.pasals[i] = form.pasals[j]; form.pasals[j] = tmp;
}
function save() { form.put(route('bs.spk.draft.update'), { preserveScroll: true }); }
function resetDefault() {
    if (!confirm(`Kembalikan draft SPK ${props.jenisOpts[props.jenis]} ke default bawaan? Konfigurasi tersimpan akan dihapus.`)) return;
    router.delete(route('bs.spk.draft.reset'), { data: { jenis: props.jenis }, preserveScroll: true });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const areaCls = 'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCog class="h-6 w-6 text-amber-600" /> Set Up SPK</h1>
                <p class="text-sm text-neutral-500">Template dokumen <b>Cetak SPK</b> per jenis SPK. Placeholder <code>{…}</code> otomatis diisi data SPK saat dicetak.</p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="secondary" @click="resetDefault"><template #icon-left><RotateCcw class="h-4 w-4" /></template>Default</Button>
                <Button :disabled="form.processing" @click="save"><template #icon-left><Save class="h-4 w-4" /></template>Simpan</Button>
            </div>
        </div>

        <!-- Tab jenis SPK: tiap jenis punya template sendiri (Housing & Infra beda judul pekerjaan + lingkup Pasal 1) -->
        <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button v-for="(lbl, key) in jenisOpts" :key="key" type="button" @click="switchJenis(key)"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                :class="key === jenis ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                {{ lbl }}
            </button>
        </div>

        <div v-if="isDefault" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300">
            Belum ada draft tersimpan untuk <b>{{ jenisOpts[jenis] }}</b> — menampilkan default bawaan (salinan draft SPK asli). Klik <b>Simpan</b> untuk mengaktifkan sebagai template.
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_260px]">
            <div class="space-y-4">
                <!-- Kepala dokumen -->
                <div class="card space-y-3 p-4">
                    <h2 class="text-sm font-semibold uppercase text-neutral-500">Kepala Dokumen</h2>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Baris Proyek (paling atas)</label><input v-model="form.proyek_line" :class="inputCls" placeholder="PROYEK {proyek}" /></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Judul</label><input v-model="form.judul" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sub-judul</label><input v-model="form.subjudul" :class="inputCls" /></div>
                    </div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Judul Pekerjaan</label><input v-model="form.judul_pekerjaan" :class="inputCls" placeholder="PEMBANGUNAN RUMAH “{proyek} {cluster} {tahap}”" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tempat (kosongkan = ikut kota perusahaan)</label><input v-model="form.tempat" :class="inputCls" placeholder="mis. Sidoarjo" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kalimat Pembukaan</label><textarea v-model="form.pembukaan" rows="2" :class="areaCls"></textarea></div>
                </div>

                <!-- Para pihak & mukadimah -->
                <div class="card space-y-3 p-4">
                    <h2 class="text-sm font-semibold uppercase text-neutral-500">Para Pihak &amp; Mukadimah</h2>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kedudukan PIHAK PERTAMA</label><textarea v-model="form.pihak1_teks" rows="3" :class="areaCls" placeholder="Dalam hal ini bertindak sesuai jabatannya sebagai Direktur {perusahaan} …"></textarea></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kedudukan PIHAK KEDUA</label><textarea v-model="form.pihak2_teks" rows="3" :class="areaCls" placeholder="Dalam hal ini bertindak sesuai jabatannya sebagai Direktur {kontraktor} …"></textarea></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Mukadimah (“Bahwa Pihak Pertama selaku Developer …”)</label><textarea v-model="form.mukadimah" rows="4" :class="areaCls"></textarea></div>
                </div>

                <!-- Pasal -->
                <div class="card p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase text-neutral-500">Pasal ({{ form.pasals.length }})</h2>
                        <Button size="sm" variant="secondary" @click="addPasal"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Pasal</Button>
                    </div>
                    <div class="space-y-3">
                        <div v-for="(p, i) in form.pasals" :key="i" class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                            <div class="mb-2 flex items-center gap-2">
                                <span class="text-xs font-semibold text-neutral-400">#{{ i + 1 }}</span>
                                <input v-model="p.title" :class="[inputCls, 'flex-1 font-semibold']" placeholder="Judul pasal" />
                                <button @click="move(i, -1)" :disabled="i === 0" class="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800"><ArrowUp class="h-4 w-4" /></button>
                                <button @click="move(i, 1)" :disabled="i === form.pasals.length - 1" class="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800"><ArrowDown class="h-4 w-4" /></button>
                                <button @click="delPasal(i)" class="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"><Trash2 class="h-4 w-4" /></button>
                            </div>
                            <textarea v-model="p.body" rows="4" :class="areaCls" placeholder="Isi pasal (boleh memakai placeholder)"></textarea>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <label class="text-xs font-medium text-neutral-500">Sisipkan tabel di bawah pasal</label>
                                <select v-model="p.table" class="h-8 rounded-lg border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                    <option value="">— tanpa tabel —</option>
                                    <option v-for="(lbl, key) in tables" :key="key" :value="key">{{ lbl }}</option>
                                </select>
                                <span v-if="p.table" class="text-[11px] text-neutral-400">Tabel terisi otomatis dari data SPK saat dicetak.</span>
                            </div>
                        </div>
                        <p v-if="!form.pasals.length" class="py-4 text-center text-sm text-neutral-400">Belum ada pasal. Klik “Tambah Pasal”.</p>
                    </div>
                </div>
            </div>

            <!-- Legenda placeholder -->
            <div class="card h-fit p-4">
                <h2 class="mb-2 text-sm font-semibold uppercase text-neutral-500">Placeholder</h2>
                <p class="mb-3 text-xs text-neutral-500">Salin ke dalam pasal; diganti otomatis saat cetak.</p>
                <ul class="space-y-1.5 text-xs">
                    <li v-for="(desc, key) in placeholders" :key="key" class="flex flex-col">
                        <code class="font-mono text-primary-600">{{ key }}</code>
                        <span class="text-neutral-500">{{ desc }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
