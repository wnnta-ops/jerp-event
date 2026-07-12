<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleDollarSign, ArrowRight } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<{ no: number; booking_id: number; unit_id: number | null; spr: string | null; unit: string | null; customer: string | null; ajb_date: string | null; bast_date: string | null }>;
    filters: { search?: string };
}>();

function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
// Booked Revenue hanya aktif bila tanggal BAST DAN tanggal AJB sudah terisi keduanya.
const ready = (r: { bast_date: string | null; ajb_date: string | null }) => !!(r.bast_date && r.ajb_date);
const f = reactive({ search: props.filters.search ?? '' });
let t: any;
watch(f, (val) => { clearTimeout(t); t = setTimeout(() => router.get(route('bs.booked-revenue.belum'), { search: val.search || undefined }, { preserveState: true, replace: true }), 300); });
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Sudah Booked Revenue</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CircleDollarSign class="h-6 w-6 text-amber-500" /> Belum Booked Revenue</h1>
            <p class="text-sm text-neutral-500">Unit yang sudah <b>BAST</b> atau <b>AJB</b> namun belum di-booking pendapatannya. Tombol <b>Booked Revenue</b> aktif setelah tanggal BAST &amp; AJB terisi keduanya.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari SPR / customer / unit…" :class="[inputCls, 'w-72']" />
            <span class="text-xs text-neutral-500">{{ rows.filter(ready).length }} dari {{ rows.length }} unit siap di-booking</span>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3 w-12">No</th>
                    <th class="px-4 py-3">No. SPR</th>
                    <th class="px-4 py-3">Unit Rumah</th>
                    <th class="px-4 py-3">Nama Customer</th>
                    <th class="px-4 py-3">Tgl AJB</th>
                    <th class="px-4 py-3">Tgl BAST</th>
                    <th class="px-4 py-3 text-center">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.booking_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 tabular-nums text-neutral-500">{{ r.no }}</td>
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.spr ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.ajb_date) }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.bast_date) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <Link v-if="ready(r)" :href="route('bs.booked-revenue.create', { unit_id: r.unit_id })"
                                  class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-700">
                                Booked Revenue <ArrowRight class="h-3.5 w-3.5" />
                            </Link>
                            <span v-else class="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg bg-neutral-200 px-3 py-1.5 text-xs font-semibold text-neutral-400 dark:bg-neutral-800 dark:text-neutral-600" title="Perlu tanggal BAST dan AJB terisi keduanya">
                                Booked Revenue <ArrowRight class="h-3.5 w-3.5" />
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada unit untuk Booked Revenue" description="Unit muncul di sini setelah BAST atau AJB terbit, dan belum di-booking pendapatannya." />
        </div>
    </div>
</template>
