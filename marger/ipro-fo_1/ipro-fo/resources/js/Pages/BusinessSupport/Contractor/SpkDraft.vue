<!-- Set Up SPK — konfigurasi draft/template default untuk Cetak SPK -->
<script setup lang="ts">
import { useForm, router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCog, Plus, Trash2, ArrowUp, ArrowDown, Save, RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    draft: { judul: string; subjudul: string; tempat: string | null; pembukaan: string | null; pasals: Array<{ title: string; body: string }> };
    isDefault: boolean;
    placeholders: Record<string, string>;
}>();

const form = useForm({
    judul: props.draft.judul ?? '',
    subjudul: props.draft.subjudul ?? '',
    tempat: props.draft.tempat ?? '',
    pembukaan: props.draft.pembukaan ?? '',
    pasals: (props.draft.pasals ?? []).map((p) => ({ title: p.title, body: p.body })),
});

function addPasal() { form.pasals.push({ title: `PASAL ${form.pasals.length + 1} — `, body: '' }); }
function delPasal(i: number) { form.pasals.splice(i, 1); }
function move(i: number, d: number) {
    const j = i + d;
    if (j < 0 || j >= form.pasals.length) return;
    const tmp = form.pasals[i]; form.pasals[i] = form.pasals[j]; form.pasals[j] = tmp;
}
function save() { form.put(route('bs.spk.draft.update'), { preserveScroll: true }); }
function resetDefault() {
    if (!confirm('Kembalikan draft SPK ke default bawaan? Konfigurasi tersimpan akan dihapus.')) return;
    router.delete(route('bs.spk.draft.reset'), { preserveScroll: true });
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
                <p class="text-sm text-neutral-500">Template default dokumen <b>Cetak SPK</b>. Placeholder <code>{…}</code> otomatis diisi data SPK saat dicetak.</p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="secondary" @click="resetDefault"><template #icon-left><RotateCcw class="h-4 w-4" /></template>Default</Button>
                <Button :disabled="form.processing" @click="save"><template #icon-left><Save class="h-4 w-4" /></template>Simpan</Button>
            </div>
        </div>

        <div v-if="isDefault" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300">
            Belum ada draft tersimpan — menampilkan default bawaan. Klik <b>Simpan</b> untuk mengaktifkan sebagai template.
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_260px]">
            <div class="space-y-4">
                <!-- Kepala dokumen -->
                <div class="card space-y-3 p-4">
                    <h2 class="text-sm font-semibold uppercase text-neutral-500">Kepala Dokumen</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Judul</label><input v-model="form.judul" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sub-judul</label><input v-model="form.subjudul" :class="inputCls" /></div>
                    </div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tempat (kosongkan = ikut kota perusahaan)</label><input v-model="form.tempat" :class="inputCls" placeholder="mis. Sidoarjo" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kalimat Pembukaan</label><textarea v-model="form.pembukaan" rows="2" :class="areaCls"></textarea></div>
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
                            <textarea v-model="p.body" rows="3" :class="areaCls" placeholder="Isi pasal (boleh memakai placeholder)"></textarea>
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
