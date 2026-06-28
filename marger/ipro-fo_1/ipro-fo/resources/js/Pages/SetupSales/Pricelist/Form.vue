<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Tag, Save } from 'lucide-vue-next';

const props = defineProps<{
    projects: Array<{ id: number; name: string }>;
    selectedProject?: number | null;
    units?: Array<{ id: number; unit_code: string; type: string | null; current_price: number; status: string }>;
}>();

const projectId = ref<number | null>(props.selectedProject ?? null);

// Saat ganti project → reload halaman form dgn daftar unit project tsb.
function loadUnits() {
    if (!projectId.value) return;
    router.get(route('setup-sales.pricelist.units'), { project_id: projectId.value }, { preserveState: false, replace: true });
}
watch(projectId, (v, old) => { if (v !== old && v) loadUnits(); });

// Harga per unit (default = current_price).
const prices = ref<Record<number, number>>({});
const disc = ref<Record<number, number>>({});
if (props.units) props.units.forEach(u => { prices.value[u.id] = u.current_price; disc.value[u.id] = 0; });

const form = useForm({
    project_id: props.selectedProject ?? null,
    name: '', effective_from: new Date().toISOString().substring(0, 10), effective_to: '', activate: false,
    items: [] as Array<{ unit_id: number; price: number; discount_max_pct: number }>,
});

const changedCount = computed(() => (props.units ?? []).filter(u => Number(prices.value[u.id]) !== u.current_price).length);

function submit() {
    form.project_id = projectId.value;
    form.items = (props.units ?? []).map(u => ({ unit_id: u.id, price: Number(prices.value[u.id]) || 0, discount_max_pct: Number(disc.value[u.id]) || 0 }));
    form.post(route('setup-sales.pricelist.store'));
}
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <form @submit.prevent="submit" class="space-y-4">
        <Link :href="route('setup-sales.pricelist.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Price List</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Tag class="h-6 w-6 text-accent-600" /> Price List Baru</h1>

        <div class="card grid gap-3 p-4 sm:grid-cols-4">
            <div><label :class="labelCls">Project *</label><select v-model="projectId" :class="inputCls" required><option :value="null" disabled>— Pilih Project —</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
            <div><label :class="labelCls">Nama Price List *</label><input v-model="form.name" :class="inputCls" placeholder="mis. Pricelist Q3 2026" required /></div>
            <div><label :class="labelCls">Berlaku Dari *</label><input type="date" v-model="form.effective_from" :class="inputCls" required /></div>
            <div><label :class="labelCls">Berlaku Sampai</label><input type="date" v-model="form.effective_to" :class="inputCls" /></div>
        </div>

        <div v-if="units && units.length" class="card overflow-x-auto">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <span class="text-sm font-semibold">{{ units.length }} unit · {{ changedCount }} harga berubah</span>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.activate" class="rounded border-neutral-300" /> Langsung aktifkan &amp; terapkan ke unit</label>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Unit</th><th class="px-4 py-2">Tipe</th><th class="px-4 py-2 text-right">Harga Sekarang</th><th class="px-4 py-2 text-right w-48">Harga Baru</th><th class="px-4 py-2 text-right w-32">Diskon Maks %</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="u in units" :key="u.id" :class="Number(prices[u.id]) !== u.current_price ? 'bg-amber-50/60 dark:bg-amber-900/10' : ''">
                        <td class="px-4 py-1.5 font-mono">{{ u.unit_code }}</td>
                        <td class="px-4 py-1.5 text-neutral-500">{{ u.type ?? '—' }}</td>
                        <td class="px-4 py-1.5 text-right tabular-nums text-neutral-500">{{ rb(u.current_price) }}</td>
                        <td class="px-4 py-1.5"><input type="number" v-model.number="prices[u.id]" class="h-8 w-full rounded border border-neutral-300 bg-white px-2 text-right text-sm dark:border-neutral-700 dark:bg-neutral-900" /></td>
                        <td class="px-4 py-1.5"><input type="number" step="0.01" v-model.number="disc[u.id]" class="h-8 w-full rounded border border-neutral-300 bg-white px-2 text-right text-sm dark:border-neutral-700 dark:bg-neutral-900" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else-if="projectId" class="card p-8 text-center text-sm text-neutral-400">Tidak ada unit pada project ini.</div>
        <div v-else class="card p-8 text-center text-sm text-neutral-400">Pilih project untuk memuat daftar unit.</div>

        <button v-if="units && units.length" type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Save class="h-4 w-4" /> Simpan Price List</button>
    </form>
</template>
