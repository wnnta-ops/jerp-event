<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eraser, Search, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps<{ rows: any; filters: { search?: string } }>();

const search = ref(props.filters.search ?? '');
function apply() { router.get(route('denda.pemutihan'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const selected = ref<Set<number>>(new Set());
function toggle(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); selected.value = new Set(selected.value); }
function toggleAll() {
    const ids = (props.rows.data ?? []).map((r: any) => r.id);
    if (ids.every((i: number) => selected.value.has(i))) selected.value = new Set();
    else selected.value = new Set(ids);
}
const totalSelected = computed(() => (props.rows.data ?? []).filter((r: any) => selected.value.has(r.id)).reduce((s: number, r: any) => s + r.outstanding, 0));

const form = useForm({ denda_ids: [] as number[], reason: '' });
function waive() {
    if (!selected.value.size) return;
    if (!confirm(`Putihkan ${selected.value.size} denda (total ${rb(totalSelected.value)})? Aksi ini menghapus sisa denda.`)) return;
    form.denda_ids = Array.from(selected.value);
    form.post(route('denda.pemutihan.waive'), { onSuccess: () => { selected.value = new Set(); } });
}
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('dendas.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Denda</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Eraser class="h-6 w-6 text-danger-600" /> Pemutihan Denda</h1>
        <p class="text-sm text-neutral-500">Pilih denda yang ingin diputihkan (dihapus sisanya), lalu klik “Putihkan Terpilih”.</p>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari customer…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left">
                    <th class="px-3 py-3"><input type="checkbox" :checked="rows.data?.length && rows.data.every((r:any)=>selected.has(r.id))" @change="toggleAll" /></th>
                    <th class="px-4 py-3">Customer</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Termin</th><th class="px-4 py-3 text-right">Telat (hari)</th><th class="px-4 py-3 text-right">Denda</th><th class="px-4 py-3 text-right">Dibayar</th><th class="px-4 py-3 text-right">Sisa</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" :class="selected.has(r.id) ? 'bg-danger-50/40 dark:bg-danger-900/10' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50'">
                        <td class="px-3 py-2"><input type="checkbox" :checked="selected.has(r.id)" @change="toggle(r.id)" /></td>
                        <td class="px-4 py-2">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.termin }}</td>
                        <td class="px-4 py-2 text-right">{{ r.overdue_days }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rb(r.amount) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-success-600">{{ rb(r.amount_paid) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium text-danger-600">{{ rb(r.outstanding) }}</td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="8" class="px-4 py-8 text-center text-neutral-400">Tidak ada denda outstanding. 🎉</td></tr>
                </tbody>
            </table>
            <div v-if="rows.data?.length" class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="text-sm">{{ selected.size }} dipilih · Total sisa <b class="text-danger-600">{{ rb(totalSelected) }}</b></div>
                <button @click="waive" :disabled="!selected.size || form.processing" class="inline-flex items-center gap-2 rounded-lg bg-danger-600 px-5 py-2 text-sm font-semibold text-white hover:bg-danger-700 disabled:opacity-50"><CheckCircle2 class="h-4 w-4" /> Putihkan Terpilih</button>
            </div>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
