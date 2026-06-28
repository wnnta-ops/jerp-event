<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Tag, CheckCircle2, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ pricelist: any }>();
const act = useForm({});
const del = useForm({});
function activate() { if (confirm('Aktifkan price list ini dan terapkan harga ke unit?')) act.post(route('setup-sales.pricelist.activate', props.pricelist.id)); }
function destroy() { if (confirm('Hapus price list ini?')) del.delete(route('setup-sales.pricelist.destroy', props.pricelist.id)); }
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('setup-sales.pricelist.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Price List</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Tag class="h-6 w-6 text-accent-600" /> {{ pricelist.name }}
                <Badge :variant="pricelist.is_active ? 'success' : 'neutral'" size="sm">{{ pricelist.is_active ? 'Aktif' : 'Draft' }}</Badge>
            </h1>
            <div class="flex gap-2">
                <button v-if="!pricelist.is_active" @click="activate" class="inline-flex items-center gap-2 rounded-lg bg-success-600 px-3 py-2 text-sm font-semibold text-white hover:bg-success-700"><CheckCircle2 class="h-4 w-4" /> Aktifkan &amp; Terapkan</button>
                <button @click="destroy" class="inline-flex items-center gap-2 rounded-lg border border-danger-300 px-3 py-2 text-sm text-danger-600 hover:bg-danger-50"><Trash2 class="h-4 w-4" /> Hapus</button>
            </div>
        </div>

        <div class="card grid gap-3 p-4 text-sm sm:grid-cols-4">
            <div><div class="text-xs uppercase text-neutral-400">Project</div><div class="font-medium">{{ pricelist.project?.name ?? '—' }}</div></div>
            <div><div class="text-xs uppercase text-neutral-400">Berlaku Dari</div><div>{{ fmt(pricelist.effective_from) }}</div></div>
            <div><div class="text-xs uppercase text-neutral-400">Berlaku Sampai</div><div>{{ fmt(pricelist.effective_to) }}</div></div>
            <div><div class="text-xs uppercase text-neutral-400">Jumlah Item</div><div class="font-semibold">{{ pricelist.items?.length ?? 0 }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-2">Unit</th><th class="px-4 py-2 text-center">Status Unit</th><th class="px-4 py-2 text-right">Harga</th><th class="px-4 py-2 text-right">Diskon Maks %</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="it in pricelist.items" :key="it.id">
                        <td class="px-4 py-1.5 font-mono">{{ it.unit?.unit_code ?? ('#' + it.unit_id) }}</td>
                        <td class="px-4 py-1.5 text-center"><span class="text-xs text-neutral-500">{{ it.unit?.status ?? '—' }}</span></td>
                        <td class="px-4 py-1.5 text-right tabular-nums">{{ rb(it.price) }}</td>
                        <td class="px-4 py-1.5 text-right">{{ Number(it.discount_max_pct).toFixed(2) }}%</td>
                    </tr>
                    <tr v-if="!pricelist.items?.length"><td colspan="4" class="px-4 py-6 text-center text-neutral-400">Tidak ada item.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
