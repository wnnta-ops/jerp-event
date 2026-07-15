<script setup lang="ts">
import { ref, computed } from 'vue';
import { FileSpreadsheet } from 'lucide-vue-next';

const props = defineProps<{ rows: Array<any>; retensiCols: Array<string> }>();

const q = ref('');

// Item Collection UTJ & DP (UTJ + DP 1..6) — tiap item punya sub-kolom Tanggal & Nominal.
const utjDpItems = ['UTJ', 'DP 1', 'DP 2', 'DP 3', 'DP 4', 'DP 5', 'DP 6'];
// Item Rincian Biaya — tiap item punya sub-kolom Tanggal & Nominal.
const biayaItems = [
    { key: 'kpr', label: 'BIAYA KPR' },
    { key: 'ht', label: 'BIAYA HT' },
    { key: 'ppjb', label: 'BIAYA PPJB' },
    { key: 'ssp', label: 'BIAYA SSP' },
    { key: 'bphtb', label: 'BIAYA BPHTB' },
    { key: 'ajb', label: 'BIAYA AJB' },
];

const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    if (! s) return props.rows;
    return props.rows.filter((r) =>
        [r.no_spr, r.kode_unit, r.customer, r.tipe, r.bank, r.agent_sales, r.cara_bayar]
            .some((v) => String(v ?? '').toLowerCase().includes(s)));
});

// UTJ + DP jadi satu array 7 sel per baris.
function collectionsOf(r: any) { return [r.utj, ...(r.dp ?? [])]; }

function n(v: any) { return (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('id-ID'); }
function d(v: any) { return v ?? '—'; }
function cellT(c: any) { return c && c.t ? c.t : '—'; }
function cellN(c: any) { return c && (c.n || c.n === 0) ? Number(c.n).toLocaleString('id-ID') : '—'; }
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileSpreadsheet class="h-6 w-6 text-primary-600" /> Master Data KPR</h1>
            <input v-model="q" type="text" placeholder="Cari SPR / unit / customer / bank…"
                class="ml-auto h-9 w-72 max-w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
        </div>
        <p class="text-sm text-neutral-500">Rekap per-SPR: Master Unit, Data KPR, Collection UTJ &amp; DP, Collection Retensi, dan Rincian Biaya. Sumber: Booking/Unit/Customer, Proses KPR (bank/plafon/akad), BAST &amp; AJB, jadwal tagihan, pelepasan retensi, dan realisasi.</p>

        <div class="card overflow-x-auto">
            <table class="min-w-max text-xs">
                <thead>
                    <!-- Baris 1: grup -->
                    <tr class="bg-neutral-100 text-[11px] font-bold uppercase text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        <th rowspan="3" class="sticky left-0 z-10 border border-neutral-200 bg-neutral-100 px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-800">No</th>
                        <th colspan="11" class="border border-neutral-200 bg-emerald-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-emerald-900/30">Master Unit</th>
                        <th colspan="7"  class="border border-neutral-200 bg-sky-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-sky-900/30">Data KPR</th>
                        <th :colspan="utjDpItems.length * 2" class="border border-neutral-200 bg-amber-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-amber-900/30">Collection UTJ &amp; DP</th>
                        <th :colspan="retensiCols.length * 2" class="border border-neutral-200 bg-rose-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-rose-900/30">Collection Retensi</th>
                        <th :colspan="biayaItems.length * 2" class="border border-neutral-200 bg-violet-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-violet-900/30">Rincian Biaya</th>
                    </tr>
                    <!-- Baris 2: item -->
                    <tr class="bg-neutral-50 text-[10px] font-semibold uppercase text-neutral-500 dark:bg-neutral-800/60">
                        <!-- Master Unit (label rowspan 2) -->
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">No. SPR</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Kode Unit</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Nama Customer</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tipe Rumah</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Pricelist Gross</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Diskon</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Free DP</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Pricelist Nett</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Revenue</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Agent / Sales</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Cara Bayar</th>
                        <!-- Data KPR (label rowspan 2) -->
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal BAST</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal AJB</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Cabang Bank</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal Akad</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Pengajuan KPR</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">ACC KPR</th>
                        <th rowspan="2" class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Selisih</th>
                        <!-- Collection UTJ & DP (item colspan 2) -->
                        <th v-for="it in utjDpItems" :key="'h-'+it" colspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ it }}</th>
                        <!-- Collection Retensi (item colspan 2) -->
                        <th v-for="rc in retensiCols" :key="'hr-'+rc" colspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700" :title="rc">{{ rc }}</th>
                        <!-- Rincian Biaya (item colspan 2) -->
                        <th v-for="bi in biayaItems" :key="'hb-'+bi.key" colspan="2" class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ bi.label }}</th>
                    </tr>
                    <!-- Baris 3: Tanggal / Nominal untuk grup berpasangan -->
                    <tr class="bg-neutral-50 text-[10px] font-medium uppercase text-neutral-400 dark:bg-neutral-800/40">
                        <template v-for="it in utjDpItems" :key="'s-'+it">
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal</th>
                            <th class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Nominal</th>
                        </template>
                        <template v-for="rc in retensiCols" :key="'sr-'+rc">
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal</th>
                            <th class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Nominal</th>
                        </template>
                        <template v-for="bi in biayaItems" :key="'sb-'+bi.key">
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Tanggal</th>
                            <th class="border border-neutral-200 px-2 py-1 text-right dark:border-neutral-700">Nominal</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="text-neutral-700 dark:text-neutral-200">
                    <tr v-for="r in filtered" :key="r.no_spr" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                        <td class="sticky left-0 z-10 border border-neutral-200 bg-white px-2 py-1 text-center dark:border-neutral-700 dark:bg-neutral-900">{{ r.no }}</td>
                        <!-- Master Unit -->
                        <td class="border border-neutral-200 px-2 py-1 font-mono font-semibold dark:border-neutral-700">{{ r.no_spr }}</td>
                        <td class="border border-neutral-200 px-2 py-1 font-mono dark:border-neutral-700">{{ d(r.kode_unit) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 whitespace-nowrap dark:border-neutral-700">{{ d(r.customer) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ d(r.tipe) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.pricelist_gross) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.diskon) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.free_dp) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.pricelist_nett) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.revenue) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 whitespace-nowrap dark:border-neutral-700">{{ d(r.agent_sales) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 whitespace-nowrap dark:border-neutral-700">{{ d(r.cara_bayar) }}</td>
                        <!-- Data KPR -->
                        <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ d(r.tgl_bast) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ d(r.tgl_ajb) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 whitespace-nowrap dark:border-neutral-700">{{ d(r.bank) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ d(r.tgl_akad) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.pengajuan_kpr) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.acc_kpr) }}</td>
                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ n(r.selisih) }}</td>
                        <!-- Collection UTJ & DP -->
                        <template v-for="(c, i) in collectionsOf(r)" :key="'c-'+r.no_spr+'-'+i">
                            <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ cellT(c) }}</td>
                            <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ cellN(c) }}</td>
                        </template>
                        <!-- Collection Retensi -->
                        <template v-for="(c, i) in (r.retensi ?? [])" :key="'rt-'+r.no_spr+'-'+i">
                            <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ cellT(c) }}</td>
                            <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ cellN(c) }}</td>
                        </template>
                        <!-- Rincian Biaya -->
                        <template v-for="bi in biayaItems" :key="'b-'+r.no_spr+'-'+bi.key">
                            <td class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">{{ cellT(r.biaya?.[bi.key]) }}</td>
                            <td class="border border-neutral-200 px-2 py-1 text-right font-mono dark:border-neutral-700">{{ cellN(r.biaya?.[bi.key]) }}</td>
                        </template>
                    </tr>
                    <tr v-if="! filtered.length">
                        <td :colspan="19 + utjDpItems.length*2 + retensiCols.length*2 + biayaItems.length*2" class="px-4 py-8 text-center text-sm text-neutral-500">
                            {{ q ? `Tidak ada hasil untuk "${q}".` : 'Belum ada data KPR.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
