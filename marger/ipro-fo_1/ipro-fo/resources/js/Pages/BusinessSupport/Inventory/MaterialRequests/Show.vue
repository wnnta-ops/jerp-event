<script setup lang="ts">
import { router, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ request: any }>();
const page = usePage();
const can = computed(() => (page.props.auth.user?.permissions ?? []) as string[]);
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
function approve() { if (!confirm(`Setujui FPB ${props.request.mr_number}?`)) return; router.post(route('bs.fpb.approve', props.request.id), {}, { preserveScroll: true }); }
const statusColor: Record<string,any> = { draft:'warning', approved:'success', closed:'neutral' };
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.fpb.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Daftar FPB</Link>
        <div class="flex items-center justify-between">
            <h1 class="font-mono text-2xl font-bold">{{ request.mr_number }}</h1>
            <div class="flex items-center gap-3">
                <Badge :variant="statusColor[request.status]" dot>{{ request.status }}</Badge>
                <Button v-if="request.status==='draft' && can.includes('businesssupport.approval.act')" @click="approve"><template #icon-left><Check class="h-4 w-4" /></template>Approve</Button>
            </div>
        </div>
        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(request.request_date) }}</div></div>
            <div><div class="text-neutral-500">Dibutuhkan</div><div class="font-medium">{{ fmtDate(request.needed_date) }}</div></div>
            <div><div class="text-neutral-500">Cost Center</div><div class="font-medium">{{ request.cost_center?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Departemen</div><div class="font-medium">{{ request.department ?? '—' }}</div></div>
            <div class="md:col-span-2"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ request.notes ?? '—' }}</div></div>
        </div>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Item</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Spesifikasi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in request.lines" :key="l.id">
                        <td class="px-4 py-2.5">{{ l.item?.code }} - {{ l.item?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.qty).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2.5">{{ l.kind }}</td>
                        <td class="px-4 py-2.5">{{ l.spec ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
