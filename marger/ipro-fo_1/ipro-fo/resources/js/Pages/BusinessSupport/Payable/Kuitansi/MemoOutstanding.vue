<!-- Memo Urgent yang sudah dibayar di Kuitansi tapi invoice hutangnya belum masuk (wajib menyusul). -->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FileWarning } from 'lucide-vue-next';

const props = defineProps<{
    rows: Array<{ memo_id: number; memo_number: string; subject: string; supplier: string | null; pengaju: string | null;
        kuitansi_id: number; kuitansi_number: string; tanggal_bayar: string; nilai: number; umur: number; lewat: boolean; approval_url: string | null }>;
    batasHari: number;
}>();

function fmtRp(v: number) { return 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID'); }
const cari = ref('');
const tampil = computed(() => {
    const q = cari.value.trim().toLowerCase();
    return q ? props.rows.filter((r) => [r.memo_number, r.subject, r.supplier, r.pengaju, r.kuitansi_number].some((x) => String(x ?? '').toLowerCase().includes(q))) : props.rows;
});
const total = computed(() => tampil.value.reduce((t, r) => t + r.nilai, 0));
const jumlahLewat = computed(() => props.rows.filter((r) => r.lewat).length);
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><FileWarning class="h-6 w-6 text-red-600" /> Memo Urgent Belum Invoice</h1>
                <p class="text-sm text-neutral-500">Memo yang sudah dibayar di Pengeluaran Kas &amp; Bank (AP) tapi invoice hutangnya belum dibuat. Batas {{ batasHari }} hari — lewat batas, pengajunya tidak bisa mengajukan memo baru.</p>
            </div>
            <input v-model="cari" type="search" placeholder="Cari memo / supplier / pengaju…" class="h-9 w-72 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs text-neutral-500">Memo belum invoice</div><div class="text-2xl font-bold">{{ rows.length }}</div></div>
            <div class="card p-4"><div class="text-xs text-neutral-500">Lewat batas {{ batasHari }} hari</div><div class="text-2xl font-bold" :class="jumlahLewat ? 'text-red-600' : ''">{{ jumlahLewat }}</div></div>
            <div class="card p-4"><div class="text-xs text-neutral-500">Total dibayar (uang muka)</div><div class="font-mono text-xl font-bold">{{ fmtRp(total) }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
                    <tr><th class="px-3 py-2">No. Memo</th><th class="px-3 py-2">Perihal</th><th class="px-3 py-2">Supplier</th><th class="px-3 py-2">Pengaju</th><th class="px-3 py-2">Kuitansi</th><th class="px-3 py-2">Tgl Bayar</th><th class="px-3 py-2 text-right">Dibayar</th><th class="px-3 py-2 text-right">Umur</th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in tampil" :key="r.kuitansi_id" class="border-t border-neutral-100 dark:border-neutral-800" :class="r.lewat ? 'bg-red-50/70 dark:bg-red-950/20' : ''">
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs"><a v-if="r.approval_url" :href="r.approval_url" class="text-primary-600 hover:underline">{{ r.memo_number }}</a><span v-else>{{ r.memo_number }}</span></td>
                        <td class="px-3 py-2">{{ r.subject }}</td>
                        <td class="px-3 py-2">{{ r.supplier || '—' }}</td>
                        <td class="px-3 py-2">{{ r.pengaju || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs"><Link :href="route('bs.ap.kuitansi.show', r.kuitansi_id)" class="text-primary-600 hover:underline">{{ r.kuitansi_number }}</Link></td>
                        <td class="whitespace-nowrap px-3 py-2">{{ r.tanggal_bayar }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ fmtRp(r.nilai) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right" :class="r.lewat ? 'font-bold text-red-600' : ''">{{ r.umur }} hari<span v-if="r.lewat"> · lewat</span></td>
                    </tr>
                    <tr v-if="!tampil.length"><td colspan="8" class="px-3 py-8 text-center text-neutral-400">{{ rows.length ? 'Tidak ada yang cocok.' : 'Semua Memo Urgent yang dibayar sudah punya invoice hutang.' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
