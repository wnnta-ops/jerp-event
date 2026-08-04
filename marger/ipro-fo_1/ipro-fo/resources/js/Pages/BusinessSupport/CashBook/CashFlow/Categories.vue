<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Pencil, Trash2, Search, Layers, TrendingUp, TrendingDown, PiggyBank } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

type Cat = { id: number; section: string; group_name: string | null; code: string; name: string; sort_order: number; is_active: boolean };
const props = defineProps<{
    categories: Cat[];
    sections: Array<{ id: string; label: string }>;
    groups: string[];
}>();

// Warna & ikon per seksi — dipakai konsisten di kartu, tabel, dan modal.
const TONE: Record<string, { ring: string; chip: string; icon: any }> = {
    inflow_ops:     { ring: 'ring-emerald-200 dark:ring-emerald-800/60', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300', icon: TrendingUp },
    outflow:        { ring: 'ring-rose-200 dark:ring-rose-800/60',       chip: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',             icon: TrendingDown },
    inflow_non_ops: { ring: 'ring-sky-200 dark:ring-sky-800/60',         chip: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',                 icon: PiggyBank },
};
const tone = (s: string) => TONE[s] ?? TONE.outflow;
const sectionLabel = (s: string) => props.sections.find(x => x.id === s)?.label ?? s;

const q = ref('');
const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.categories.filter(c => `${c.code} ${c.name} ${c.group_name ?? ''}`.toLowerCase().includes(s)) : props.categories;
});
// Dikelompokkan seksi → grup, urut sesuai urutan seksi pada laporan.
const blocks = computed(() => props.sections.map(sec => {
    const inSec = filtered.value.filter(c => c.section === sec.id);
    const groups = new Map<string, Cat[]>();
    for (const c of inSec) {
        const g = c.group_name ?? '';
        if (!groups.has(g)) groups.set(g, []);
        groups.get(g)!.push(c);
    }
    return { ...sec, total: inSec.length, groups: Array.from(groups, ([label, items]) => ({ label, items })) };
}).filter(b => b.total > 0 || !q.value));

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ section: 'inflow_ops', group_name: '', code: '', name: '', sort_order: 0, is_active: true });

function openCreate(section?: string) {
    editingId.value = null;
    form.reset();
    form.section = section ?? 'inflow_ops';
    form.sort_order = (props.categories.reduce((m, c) => Math.max(m, c.sort_order), 0) || 0) + 10;
    showModal.value = true;
}
function openEdit(c: Cat) {
    editingId.value = c.id;
    Object.assign(form, { section: c.section, group_name: c.group_name ?? '', code: c.code, name: c.name, sort_order: c.sort_order, is_active: c.is_active });
    form.clearErrors();
    showModal.value = true;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.cashflow.categories.update', editingId.value), opts) : form.post(route('bs.cashflow.categories.store'), opts);
}
function destroy(c: Cat) {
    if (!confirm(`Hapus kategori "${c.name}"?`)) return;
    router.delete(route('bs.cashflow.categories.destroy', c.id), { preserveScroll: true });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Set Up Kategori Cash Flow <span class="text-base font-normal text-neutral-400">({{ categories.length }})</span></h1>
            <Button @click="openCreate()"><template #icon-left><Plus class="h-4 w-4" /></template>Kategori</Button>
        </div>
        <p class="text-sm text-neutral-500">
            Kategori inilah yang muncul sebagai pilihan <b>Pembagian Biaya per Cash Flow</b> di Rekening Ledger, dan menjadi baris-baris pada <b>Report Cashflow</b>.
            Grup hanya berlaku untuk seksi <b>CASH OUT</b>.
        </p>

        <!-- Ringkasan per seksi -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div v-for="s in sections" :key="s.id" class="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 dark:bg-neutral-900" :class="tone(s.id).ring">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="tone(s.id).chip"><component :is="tone(s.id).icon" class="h-4 w-4" /></span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold">{{ s.label }}</div>
                    <div class="text-xs text-neutral-500">{{ categories.filter(c => c.section === s.id).length }} kategori</div>
                </div>
                <button type="button" @click="openCreate(s.id)" class="ml-auto shrink-0 rounded-lg border border-neutral-300 p-1.5 text-neutral-500 transition-colors duration-200 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800" title="Tambah kategori di seksi ini"><Plus class="h-3.5 w-3.5" /></button>
            </div>
        </div>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="q" placeholder="Cari kategori…" :class="[inputCls,'pl-9']" /></div>

        <div v-for="b in blocks" :key="b.id" class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b px-4 py-2.5" :class="tone(b.id).ring.replace('ring-', 'border-')">
                <span class="rounded-md px-2 py-0.5 text-xs font-bold tracking-wide" :class="tone(b.id).chip">{{ b.label }}</span>
                <span class="text-xs text-neutral-400">{{ b.total }} kategori</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2 w-16 text-right">Urut</th><th class="px-4 py-2">Nama Kategori</th><th class="px-4 py-2 w-64">Kode</th>
                    <th class="px-4 py-2 w-24 text-center">Status</th><th class="px-4 py-2 w-24 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="g in b.groups" :key="b.id + '-' + g.label">
                        <tr v-if="g.label" class="bg-neutral-50/70 dark:bg-neutral-800/40">
                            <td></td><td colspan="4" class="px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-neutral-500">{{ g.label }}</td>
                        </tr>
                        <tr v-for="c in g.items" :key="c.id" class="transition-colors duration-150 hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                            <td class="px-4 py-2 text-right font-mono text-xs text-neutral-400">{{ c.sort_order }}</td>
                            <td class="px-4 py-2" :class="c.is_active ? '' : 'text-neutral-400 line-through'">{{ c.name }}</td>
                            <td class="px-4 py-2 font-mono text-[11px] text-neutral-400">{{ c.code }}</td>
                            <td class="px-4 py-2 text-center"><Badge size="sm" :variant="c.is_active ? 'success' : 'neutral'">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                            <td class="px-4 py-2"><div class="flex justify-end gap-2">
                                <button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                                <button @click="destroy(c)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                            </div></td>
                        </tr>
                    </template>
                    <tr v-if="!b.total"><td colspan="5" class="px-4 py-6 text-center text-sm text-neutral-400">Tidak ada kategori pada seksi ini.</td></tr>
                </tbody>
            </table>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Cash Flow</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-neutral-500">Seksi *</label>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <button v-for="s in sections" :key="s.id" type="button" @click="form.section = s.id"
                                    class="rounded-lg border px-2 py-2 text-xs font-semibold transition-all duration-200"
                                    :class="form.section === s.id ? 'border-primary-500 bg-primary-50 text-primary-700 ring-1 ring-primary-500 dark:bg-primary-950/40 dark:text-primary-300' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'">
                                    {{ s.label }}
                                </button>
                            </div>
                        </div>
                        <div v-if="form.section === 'outflow'">
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Grup</label>
                            <input v-model="form.group_name" list="cf-groups" :class="inputCls" placeholder="mis. KONTRAKTOR" />
                            <datalist id="cf-groups"><option v-for="g in groups" :key="g" :value="g" /></datalist>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Nama Kategori *</label>
                                <input v-model="form.name" required :class="inputCls" />
                                <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label>
                                <input v-model="form.code" required :class="[inputCls,'font-mono text-xs']" />
                                <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Urutan Tampil</label>
                                <input v-model.number="form.sort_order" type="number" min="0" :class="inputCls" />
                            </div>
                            <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
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
