<script setup lang="ts">
// Transaksi Bon Batal (Riwayat) — pengajuan yang DITOLAK sebelum dibayar.
//
// BACA-SAJA, dan memang tidak ada yang bisa dilakukan di sini: bon ini tidak pernah dibayar,
// jadi tidak ada jurnal yang menyertainya dan tidak ada yang perlu dibalik. Layarnya ada
// supaya penolakan bisa ditelusuri — siapa menolak, kapan, dan kenapa.
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Ban, Search } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: any;
    filters: { search?: string };
    total: number;
}>();

const cari = ref(props.filters.search ?? '');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: string | null) {
    if (!v) return '—';
    const d = new Date(v + 'T00:00:00');
    return isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

let jeda: ReturnType<typeof setTimeout>;
watch(cari, () => {
    clearTimeout(jeda);
    jeda = setTimeout(() => {
        router.get(route('bs.advance.cancelled'), { search: cari.value || undefined },
            { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.advance.payments')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Bon Sementara Finance
        </Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Ban class="h-6 w-6 text-danger-500" /> Transaksi Bon Batal</h1>
            <p class="text-sm text-neutral-500">
                Pengajuan yang <b>ditolak sebelum dibayar</b>. Tidak ada jurnal yang menyertainya —
                uangnya memang tidak pernah keluar. Riwayat ini ada supaya penolakannya bisa ditelusuri.
            </p>
        </div>

        <div class="card flex flex-wrap items-center gap-3 p-3">
            <div class="relative min-w-[16rem] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="cari" type="search" placeholder="Cari no. bon, pemegang, keperluan, atau alasan…"
                       class="h-10 w-full rounded-lg border border-neutral-300 pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <span class="text-sm text-neutral-500">Total nilai dibatalkan: <b class="font-mono">{{ fmtRp(total) }}</b></span>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3">No. Bon</th>
                    <th class="px-3 py-3">Tanggal</th>
                    <th class="px-3 py-3">Pemegang</th>
                    <th class="px-3 py-3">Keperluan</th>
                    <th class="px-3 py-3 text-right">Nominal</th>
                    <th class="px-3 py-3">Dibatalkan</th>
                    <th class="px-3 py-3">Alasan</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5">
                            <Link :href="route('bs.advance.show', r.id)" class="font-mono font-semibold text-primary-600 hover:underline">{{ r.advance_number }}</Link>
                        </td>
                        <td class="px-3 py-2.5">{{ fmtTgl(r.advance_date) }}</td>
                        <td class="px-3 py-2.5">{{ r.holder }}</td>
                        <td class="px-3 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                        <td class="px-3 py-2.5 text-xs text-neutral-500">
                            {{ r.cancelled_at }}
                            <div v-if="r.cancelled_by" class="text-neutral-400">oleh {{ r.cancelled_by }}</div>
                        </td>
                        <!-- Alasan ditampilkan UTUH, tidak dipotong: inilah satu-satunya tempat
                             jawaban "kenapa ditolak" tercatat. -->
                        <td class="px-3 py-2.5 text-neutral-600 dark:text-neutral-300">{{ r.cancel_reason || '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <EmptyState v-if="!rows.data.length" title="Belum ada bon yang dibatalkan"
                        description="Pengajuan yang ditolak Finance sebelum dibayar akan tercatat di sini." />

            <div v-if="rows.links && rows.last_page > 1" class="flex flex-wrap gap-1 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" class="rounded px-3 py-1 text-sm"
                      :class="[l.active ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                               !l.url && 'pointer-events-none opacity-40']" v-html="l.label" />
            </div>
        </div>
    </div>
</template>
