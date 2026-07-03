<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ClipboardList, Info } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Opt = { level: string; desc: string; hint: string | null; score: number; bank: number[] };
type Aspect = { id: number; aspect_key: string; aspect_no: number; label: string; scope: string; is_active: boolean; options: Opt[] };
const props = defineProps<{
    aspects: Aspect[];
    scopes: Record<string, string>;
    levels: Record<string, string>;
    banks: string[];
}>();

const LEVEL_ORDER = ['high', 'medium', 'low'];
const levelCls: Record<string, string> = {
    high: 'bg-success-500 text-white', medium: 'bg-warning-500 text-white', low: 'bg-danger-500 text-white',
};
const levelSoft: Record<string, string> = {
    high: 'border-success-300 bg-success-50 dark:border-success-800 dark:bg-success-900/15',
    medium: 'border-warning-300 bg-warning-50 dark:border-warning-800 dark:bg-warning-900/15',
    low: 'border-danger-300 bg-danger-50 dark:border-danger-800 dark:bg-danger-900/15',
};
function aspectsOf(scope: string) { return props.aspects.filter((a) => a.scope === scope).sort((a, b) => a.aspect_no - b.aspect_no); }
function opt(a: Aspect, level: string): Opt | undefined { return (a.options || []).find((o) => o.level === level); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
function blankOptions(): Opt[] { return LEVEL_ORDER.map((level, i) => ({ level, desc: '', hint: '', score: 3 - i, bank: props.banks.map(() => 0) })); }
const form = useForm<any>({ label: '', scope: 'all', aspect_no: 0, is_active: true, options: blankOptions() });

function openCreate() {
    editingId.value = null;
    form.reset();
    form.options = blankOptions();
    form.is_active = true;
    showModal.value = true;
}
function openEdit(a: Aspect) {
    editingId.value = a.id;
    form.label = a.label; form.scope = a.scope; form.aspect_no = a.aspect_no; form.is_active = !!a.is_active;
    form.options = LEVEL_ORDER.map((level, i) => {
        const o = opt(a, level);
        return { level, desc: o?.desc ?? '', hint: o?.hint ?? '', score: Number(o?.score ?? (3 - i)), bank: props.banks.map((_, bi) => Number(o?.bank?.[bi] ?? 0)) };
    });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('kpr.setup.analisa.update', editingId.value), opts) : form.post(route('kpr.setup.analisa.store'), opts);
}
function destroy(a: Aspect) {
    if (!confirm(`Hapus aspek "${a.label}"? Penilaian aspek ini tidak lagi diminta di Analisa.`)) return;
    router.delete(route('kpr.setup.analisa.destroy', a.id), { preserveScroll: true });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const numCls = 'h-8 w-12 rounded-md border border-neutral-300 bg-white text-center text-sm dark:border-neutral-700 dark:bg-neutral-900';
const total = computed(() => props.aspects.length);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> Kategori Analisa Berkas</h1>
                <p class="text-sm text-neutral-500">Konfigurasi scoring HIGH / MEDIUM / LOW tahap <b>Analisa BI Checking &amp; Berkas</b>. Nilai skor bebas diubah (tidak harus 3/2/1). {{ total }} aspek.</p>
            </div>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Aspek</Button>
        </div>

        <div class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs text-sky-800 dark:border-sky-800 dark:bg-sky-900/20 dark:text-sky-200">
            <Info class="mt-0.5 h-4 w-4 shrink-0" />
            <span><b>Nilai Skor</b> menentukan indikator keseluruhan (rata-rata skor → HIGH ≥2.5, MEDIUM ≥1.5, selebihnya LOW). <b>Skor Bank</b> ({{ banks.join(' · ') }}) menentukan 2 Rekomendasi Bank di Profiling. Aspek <b>Karyawan</b>/<b>Wirausaha</b> hanya muncul sesuai kategori pemohon.</span>
        </div>

        <div v-for="(scopeLabel, scope) in scopes" :key="scope" class="space-y-2">
            <div class="flex items-center gap-2 pt-1">
                <span class="text-xs font-bold uppercase tracking-wide text-accent-600">{{ scopeLabel }}</span>
                <span class="text-xs text-neutral-400">{{ aspectsOf(scope).length }} aspek</span>
            </div>

            <div v-for="a in aspectsOf(scope)" :key="a.id" class="card overflow-hidden" :class="!a.is_active ? 'opacity-60' : ''">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-2.5 dark:border-neutral-800">
                    <div class="flex items-center gap-2">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-neutral-100 text-xs font-bold text-neutral-500 dark:bg-neutral-800">{{ a.aspect_no }}</span>
                        <span class="font-semibold">{{ a.label }}</span>
                        <Badge v-if="!a.is_active" variant="neutral" size="sm">nonaktif</Badge>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="openEdit(a)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                        <button @click="destroy(a)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="bg-neutral-50 text-left text-[10px] uppercase text-neutral-500 dark:bg-neutral-800/60">
                            <tr>
                                <th class="px-4 py-1.5">Level</th><th class="px-4 py-1.5">Deskripsi</th>
                                <th class="px-2 py-1.5 text-center">Skor</th>
                                <th v-for="b in banks" :key="b" class="px-2 py-1.5 text-center">{{ b }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="level in LEVEL_ORDER" :key="level">
                                <td class="px-4 py-1.5"><span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="levelCls[level]">{{ levels[level] }}</span></td>
                                <td class="px-4 py-1.5">
                                    <span>{{ opt(a, level)?.desc }}</span>
                                    <span v-if="opt(a, level)?.hint" class="ml-1 text-xs text-neutral-400">· {{ opt(a, level)?.hint }}</span>
                                </td>
                                <td class="px-2 py-1.5 text-center font-mono font-semibold">{{ opt(a, level)?.score }}</td>
                                <td v-for="(b, bi) in banks" :key="bi" class="px-2 py-1.5 text-center font-mono" :class="(opt(a, level)?.bank?.[bi] ?? 0) > 0 ? 'text-primary-600' : 'text-neutral-300 dark:text-neutral-600'">{{ opt(a, level)?.bank?.[bi] ?? 0 }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p v-if="!aspectsOf(scope).length" class="px-1 text-xs text-neutral-400">Belum ada aspek untuk kategori ini.</p>
        </div>

        <EmptyState v-if="!total" title="Belum ada aspek" description="Klik Tambah Aspek untuk mulai mengonfigurasi scoring." />

        <!-- Modal tambah/edit -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="showModal = false">
                <div class="my-6 w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Aspek Analisa</h2>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-6">
                            <div class="sm:col-span-4"><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Aspek *</label><input v-model="form.label" required :class="inputCls" /><p v-if="form.errors.label" class="mt-1 text-xs text-danger-500">{{ form.errors.label }}</p></div>
                            <div class="sm:col-span-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Urutan</label><input v-model.number="form.aspect_no" type="number" min="0" :class="inputCls" /></div>
                            <div class="sm:col-span-1 flex items-end pb-1.5"><label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label></div>
                            <div class="sm:col-span-6"><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Pemohon *</label><select v-model="form.scope" :class="inputCls"><option v-for="(lbl, val) in scopes" :key="val" :value="val">{{ lbl }}</option></select></div>
                        </div>

                        <div v-for="(o, i) in form.options" :key="o.level" class="rounded-lg border p-3" :class="levelSoft[o.level]">
                            <div class="mb-2 flex items-center gap-2"><span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="levelCls[o.level]">{{ levels[o.level] }}</span></div>
                            <div class="grid gap-2 sm:grid-cols-6">
                                <div class="sm:col-span-4"><label class="mb-1 block text-[10px] uppercase text-neutral-500">Deskripsi *</label><input v-model="o.desc" required :class="inputCls" /></div>
                                <div class="sm:col-span-1"><label class="mb-1 block text-[10px] uppercase text-neutral-500">Hint</label><input v-model="o.hint" :class="inputCls" /></div>
                                <div class="sm:col-span-1"><label class="mb-1 block text-[10px] uppercase text-neutral-500">Nilai Skor</label><input v-model.number="o.score" type="number" min="0" :class="inputCls" /></div>
                            </div>
                            <div class="mt-2">
                                <label class="mb-1 block text-[10px] uppercase text-neutral-500">Skor Rekomendasi Bank</label>
                                <div class="flex flex-wrap gap-3">
                                    <label v-for="(b, bi) in banks" :key="bi" class="flex items-center gap-1 text-xs"><span class="w-14 text-neutral-500">{{ b }}</span><input v-model.number="o.bank[bi]" type="number" min="0" :class="numCls" /></label>
                                </div>
                            </div>
                            <p v-if="form.errors[`options.${i}.desc`]" class="mt-1 text-xs text-danger-500">{{ form.errors[`options.${i}.desc`] }}</p>
                        </div>

                        <div class="flex justify-end gap-2 pt-1">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
