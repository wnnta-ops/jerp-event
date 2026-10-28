<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3, FileSpreadsheet } from 'lucide-vue-next';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ mode: string; year: number; accounts: any[]; filters: any;
    // 'neraca' → dikendalikan satu tanggal (posisi); 'laba_rugi' → dikendalikan rentang.
    jenis: string;
    title: string; rowHeader: string; columns: string[]; rows: any[] }>();
const accountId = ref(props.filters.account_id ?? '');
const asOf = ref(props.filters.as_of);
const dari = ref(props.filters.date_from);
const sampai = ref(props.filters.date_to);
const modes = [
    { k: 'tahunan', l: 'Neraca Komparasi Tahunan' },
    { k: 'multi', l: 'Neraca Multi Periode' },
    { k: 'histori', l: 'Histori Saldo' },
    { k: 'lr-multi', l: 'Laba Rugi Multi Periode' },
    { k: 'lr-tahunan', l: 'Laba Rugi Komparasi Tahunan' },
    { k: 'lr-banding', l: 'Laba Rugi Perbandingan' },
];
/* Parameter yang berlaku untuk sebuah mode. Laporan neraca dikirimi satu tanggal,
   laporan laba rugi dikirimi rentang — mengirim keduanya sekaligus hanya akan membuat
   dua kendali yang saling bertentangan di alamat yang sama. */
function param(mode: string) {
    const lr = mode.startsWith('lr-');
    return {
        mode,
        as_of: lr ? undefined : asOf.value,
        date_from: lr ? dari.value : undefined,
        date_to: lr ? sampai.value : undefined,
        account_id: mode === 'histori' ? (accountId.value || undefined) : undefined,
    };
}
function go(mode: string) { router.get(route('bs.gl.extra'), param(mode), { preserveState: true, replace: true }); }
// Unduhan memakai filter yang SUDAH diterapkan (props), bukan isian yang belum ditekan Tampilkan.
function excel() {
    const lr = props.mode.startsWith('lr-');
    window.location.href = route('bs.gl.extra.export', {
        mode: props.mode,
        as_of: lr ? undefined : props.filters.as_of,
        date_from: lr ? props.filters.date_from : undefined,
        date_to: lr ? props.filters.date_to : undefined,
        account_id: props.mode === 'histori' ? (props.filters.account_id || undefined) : undefined,
    });
}
function fmt(v: number | null) { return v === null || v === undefined ? '' : Number(v).toLocaleString('id-ID'); }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BarChart3 class="h-6 w-6 text-primary-600" /> Laporan GL Lanjutan</h1>
        <div class="flex flex-wrap gap-2">
            <button v-for="m in modes" :key="m.k" @click="go(m.k)" class="rounded-lg border px-3 py-1.5 text-sm" :class="mode === m.k ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'">{{ m.l }}</button>
        </div>
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div v-if="jenis === 'neraca'">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Per Tanggal</label>
                <input v-model="asOf" type="date" :class="inputCls" />
            </div>
            <template v-else>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label>
                    <input v-model="dari" type="date" :class="inputCls" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label>
                    <input v-model="sampai" type="date" :class="inputCls" />
                </div>
            </template>
            <div v-if="mode === 'histori'" class="min-w-[280px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun (ketik kode / nama)</label><SearchSelect v-model="accountId" :options="accounts" placeholder="— cari akun —" /></div>
            <button @click="go(mode)" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
        </div>
        <div class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">{{ title }}</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2">{{ rowHeader }}</th><th v-for="c in columns" :key="c" class="px-4 py-2 text-right">{{ c }}</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-1.5"><span v-if="r.code" class="font-mono text-xs text-neutral-400">{{ r.code }}</span> {{ r.label }}</td>
                        <td v-for="(v, j) in r.values" :key="j" class="px-4 py-1.5 text-right font-mono">{{ fmt(v) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!rows.length" class="py-6 text-center text-sm text-neutral-400">Tidak ada data — pilih filter lalu klik Display.</p>
        </div>
    </div>
</template>
