<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ giros: any; summary: { pending: number; count: number } }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function postGl(r: any) {
    if (confirm(`Posting GL untuk ${r.giro_number}?\nJurnal terima (Dr Piutang Giro / Cr Piutang Usaha) akan dibuat.`))
        router.post(route('bs.giro.postgl', r.id), {}, { preserveScroll: true });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.giro.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> BG/Cek Masuk</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> Penerimaan Cek Giro</h1>
            <Link :href="route('bs.giro.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Terima BG/Cek</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Giro <strong>Di Tangan</strong> yang belum di-jurnal (kiriman FO Bilyet Giro / entri manual). Klik <strong>Posting GL</strong> untuk membuat jurnal penerimaan; setelah itu bisa Kliring / Tolak.</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum Posting GL</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(summary.pending) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Lembar</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3">Bank</th><th class="px-4 py-3">Penerbit</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in giros.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.giro_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.due_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.bank_name }}<span v-if="r.cheque_number" class="text-neutral-400"> · {{ r.cheque_number }}</span></td>
                        <td class="px-4 py-2.5">{{ r.drawer }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="r.source === 'fo' ? 'info' : 'neutral'" size="sm">{{ r.source === 'fo' ? 'FO' : 'Manual' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge variant="warning" size="sm">Di Tangan</Badge></td>
                        <td class="px-4 py-2.5 text-right">
                            <button @click="postGl(r)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"><Check class="h-4 w-4" /> Posting GL</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!giros.data?.length" title="Tidak ada giro menunggu Posting GL" description="Giro dari FO / entri manual yang belum di-jurnal akan tampil di sini." />
        </div>
        <Pagination :data="giros" />
    </div>
</template>
