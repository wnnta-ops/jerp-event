<!-- Approval Budget — batch approval of pending master budgets -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    canApprove: boolean;
    summary: { count: number; total: number };
}>();

function fmtRp(v: number) {
    return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
}

function fmtDate(d: string): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

const sel = ref<number[]>([]);
function toggle(id: number) {
    const i = sel.value.indexOf(id);
    i >= 0 ? sel.value.splice(i, 1) : sel.value.push(id);
}
function allChecked() {
    return props.rows.length > 0 && sel.value.length === props.rows.length;
}
function toggleAll() {
    sel.value = allChecked() ? [] : props.rows.map((r: any) => r.id);
}
function decide(decision: 'approve' | 'reject') {
    if (!sel.value.length) return;
    router.post(route('bs.budget.approve-batch'), { ids: sel.value, decision }, { preserveScroll: true, onSuccess: () => { sel.value = []; } });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Budget</h1>
                <p class="text-sm text-neutral-500"><b>{{ summary.count }}</b> master budget menunggu persetujuan · Rp {{ fmtRp(summary.total) }}</p>
            </div>

            <div v-if="canApprove" class="flex items-center gap-2">
                <Button variant="success" :disabled="sel.length === 0" @click="decide('approve')">Setujui</Button>
                <Button variant="danger" :disabled="sel.length === 0" @click="decide('reject')">Tolak</Button>
            </div>
        </div>

        <EmptyState v-if="rows.length === 0" title="Tidak ada yang menunggu persetujuan" description="Semua master budget telah diproses." />

        <div v-else class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-neutral-500 bg-neutral-50 dark:bg-neutral-800">
                        <th class="px-3 py-2 w-10">
                            <input type="checkbox" :checked="allChecked()" @change="toggleAll" />
                        </th>
                        <th class="px-3 py-2">No. Budget</th>
                        <th class="px-3 py-2">Tgl</th>
                        <th class="px-3 py-2">Akun</th>
                        <th class="px-3 py-2">Cost Center</th>
                        <th class="px-3 py-2">Periode</th>
                        <th class="px-3 py-2 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-3 py-2">
                            <input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" />
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ r.doc_no }}</td>
                        <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
                        <td class="px-3 py-2">{{ r.account }}</td>
                        <td class="px-3 py-2">{{ r.cost_center }}</td>
                        <td class="px-3 py-2">{{ r.period }}</td>
                        <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
