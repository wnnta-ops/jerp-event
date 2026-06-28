<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, ArrowLeft, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ memorials: any; types: Record<string, string>; filters: { type?: string } }>();
const type = ref(props.filters.type ?? '');
function applyFilter() { router.get(route('bs.ar.memorial.index'), { type: type.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-success-600" /> Memorial Piutang</h1>
            <Link :href="route('bs.ar.memorial.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Memorial</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Penyesuaian non-tunai atas piutang customer — penambahan atau pengurangan nilai.</p>

        <select v-model="type" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua tipe</option><option v-for="(label,val) in types" :key="val" :value="val">{{ label }}</option></select>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Memorial</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Akun Lawan</th><th class="px-4 py-3 text-right">Nilai</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in memorials.data" :key="m.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ m.memorial_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(m.memorial_date) }}</td>
                        <td class="px-4 py-2.5">{{ m.customer?.full_name }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="m.trx_type === 'penambahan' ? 'warning' : 'success'" size="sm">{{ types[m.trx_type] }}</Badge></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ m.counter_account?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!memorials.data?.length" title="Belum ada memorial" description="Klik Memorial untuk membuat penyesuaian piutang." />
        </div>
    </div>
</template>
