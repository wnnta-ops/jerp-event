<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, ArrowLeft, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ giros: any; statuses: Record<string, string>; filters: { status?: string }; summary: { in_hand: number; count: number } }>();
const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('bs.giro.index'), { status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const badge: Record<string, any> = { in_hand: 'warning', cleared: 'success', bounced: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> BG/Cek Masuk</h1>
            <Link :href="route('bs.giro.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Terima BG/Cek</Button></Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Giro Di Tangan</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(summary.in_hand) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Lembar Belum Cair</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
        </div>

        <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
            <option value="">Semua status</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option>
        </select>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3">Bank</th><th class="px-4 py-3">Penerbit</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in giros.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.giro.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.giro_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.due_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.bank_name }}<span v-if="r.cheque_number" class="text-neutral-400"> · {{ r.cheque_number }}</span></td>
                        <td class="px-4 py-2.5">{{ r.drawer }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="badge[r.status] ?? 'neutral'" size="sm">{{ statuses[r.status] }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!giros.data?.length" title="Belum ada BG/Cek masuk" description="Klik Terima BG/Cek untuk mencatat warkat masuk dari customer." />
        </div>
    </div>
</template>
