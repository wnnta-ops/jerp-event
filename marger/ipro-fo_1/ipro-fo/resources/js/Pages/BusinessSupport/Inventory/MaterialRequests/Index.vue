<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ClipboardList, ShoppingCart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ requests: any; filters: Record<string, any>; approvedCount: number }>();
const status = ref(props.filters.status ?? '');
function display() { router.get(route('bs.fpb.index'), { status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const statusColor: Record<string,any> = { draft:'warning', approved:'success', closed:'neutral' };
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> FPB — Permintaan Barang</h1>
            <Link :href="route('bs.fpb.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Buat FPB</Button></Link>
        </div>
        <!-- FPB yang sudah disetujui tidak lagi berada di sini; tindak lanjutnya di Purchase Order. -->
        <Link v-if="approvedCount" :href="route('bs.po.index')"
            class="flex items-center gap-2.5 rounded-xl bg-primary-50/70 px-4 py-3 text-sm ring-1 ring-primary-200 transition-colors duration-200 hover:bg-primary-100/70 dark:bg-primary-950/25 dark:ring-primary-800/60 dark:hover:bg-primary-950/45">
            <ShoppingCart class="h-4 w-4 shrink-0 text-primary-600" />
            <span><b>{{ approvedCount }} FPB</b> yang sudah disetujui berpindah ke <b>Purchase Order</b> untuk dijadikan PO.</span>
            <span class="ml-auto shrink-0 text-xs font-medium text-primary-600">Buka Purchase Order →</span>
        </Link>

        <div class="card flex items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Status</label>
                <select v-model="status" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua (belum disetujui)</option><option value="draft">Draft</option><option value="approved">Approved (ada di Purchase Order)</option><option value="closed">Closed</option></select></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. FPB</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Cost Center</th><th class="px-4 py-3">Departemen</th><th class="px-4 py-3 text-center">Item</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in requests.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.mr_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.request_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.cost_center?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.department ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="statusColor[r.status]" dot>{{ r.status }}</Badge></td>
                        <td class="px-4 py-2.5 text-right"><Link :href="route('bs.fpb.show', r.id)" class="text-primary-600 text-sm">Lihat →</Link></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!requests.data?.length" title="Belum ada FPB" description="Klik Buat FPB." />
        </div>
        <Pagination :data="requests" />
    </div>
</template>
