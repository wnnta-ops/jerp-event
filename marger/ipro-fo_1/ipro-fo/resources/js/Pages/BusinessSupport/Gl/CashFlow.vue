<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Waves, Layers } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    month: number;
    method: string | null;
    beginning: number;
    ending: number;
    cashMovement: number;
    direct: any;
    indirect: any;
    clusterBreakdown: Array<{ code: string; name: string; total: number }>;
    directTagged: Array<{ name: string; activity: string; amount: number }>;
    indirectTagged: Array<{ name: string; activity: string; amount: number }>;
}>();

const MONTHS = ['Semua Bulan', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const year = ref(props.year);
const month = ref(props.month ?? 0);
function changePeriod() { router.get(route('bs.gl.cashflow'), { year: year.value, month: month.value || undefined, method: props.method || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { const n = Number(v ?? 0); return (n < 0 ? '(Rp ' + Math.abs(n).toLocaleString('id-ID') + ')' : 'Rp ' + n.toLocaleString('id-ID')); }
const reconciled = Math.abs(props.direct.net_change - props.cashMovement) < 1;
const showDirect = computed(() => props.method !== 'indirect');
const showIndirect = computed(() => props.method !== 'direct');
const titleSuffix = computed(() => (props.method === 'direct' ? ' — Direct' : props.method === 'indirect' ? ' — Indirect' : ''));
const periodLabel = computed(() => (month.value > 0 ? MONTHS[month.value] + ' ' : '') + year.value);
const ACT: Record<string, string> = { operating: 'Operasi', investing: 'Investasi', financing: 'Pendanaan' };
const clusterTotal = computed(() => (props.clusterBreakdown ?? []).reduce((s, c) => s + c.total, 0));
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Waves class="h-6 w-6 text-primary-600" /> Laporan Arus Kas{{ titleSuffix }}</h1>
                <p class="text-sm text-neutral-500">Periode <b>{{ periodLabel }}</b> — metode {{ method === 'indirect' ? 'Tidak Langsung (Indirect)' : method === 'direct' ? 'Langsung (Direct)' : 'Langsung & Tidak Langsung' }}.</p>
            </div>
            <div class="flex items-end gap-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bulan</label><select v-model.number="month" @change="changePeriod" :class="inputCls"><option v-for="(m, i) in MONTHS" :key="i" :value="i">{{ m }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun</label><input v-model.number="year" @change="changePeriod" type="number" :class="[inputCls, 'w-24']" /></div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Saldo Kas Awal {{ periodLabel }}</div><div class="mt-1 text-lg font-bold">{{ fmtRp(beginning) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Perubahan Kas</div><div class="mt-1 text-lg font-bold" :class="cashMovement >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(cashMovement) }}</div></div>
            <div class="card p-4 ring-1 ring-primary-200 dark:ring-primary-900/40"><div class="text-xs uppercase text-neutral-400">Saldo Kas Akhir</div><div class="mt-1 text-lg font-bold text-primary-600">{{ fmtRp(ending) }}</div></div>
        </div>

        <!-- Arus Kas per Cluster (dari Pembagian Cluster di Rekening Ledger) -->
        <div v-if="clusterBreakdown.length" class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><Layers class="h-5 w-5 text-primary-600" /> Arus Kas per Cluster</h2>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in clusterBreakdown" :key="c.code" class="flex items-center justify-between rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                    <span class="text-sm"><b>{{ c.code }}</b> — {{ c.name }}</span>
                    <span class="font-mono text-sm font-semibold">{{ fmtRp(c.total) }}</span>
                </div>
            </div>
            <div class="mt-3 flex justify-between border-t border-neutral-200 pt-2 text-sm font-bold dark:border-neutral-700"><span>Total Teralokasi</span><span class="font-mono">{{ fmtRp(clusterTotal) }}</span></div>
        </div>

        <div class="grid gap-5" :class="showDirect && showIndirect ? 'lg:grid-cols-2' : 'lg:grid-cols-1'">
            <!-- DIRECT -->
            <div v-if="showDirect" class="card p-5">
                <h2 class="mb-3 flex items-center justify-between font-semibold">
                    <span>Metode Langsung (Direct)</span>
                    <Badge :variant="reconciled ? 'success' : 'warning'" size="sm">{{ reconciled ? 'Rekonsiliasi ✓' : 'Cek' }}</Badge>
                </h2>
                <div class="space-y-3 text-sm">
                    <div v-for="grp in [{k:'operating',t:'Aktivitas Operasi'},{k:'investing',t:'Aktivitas Investasi'},{k:'financing',t:'Aktivitas Pendanaan'}]" :key="grp.k">
                        <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">{{ grp.t }}</div>
                        <div v-for="r in direct[grp.k]" :key="r.code" class="flex justify-between py-0.5"><span class="text-neutral-600 dark:text-neutral-300">{{ r.name }}</span><span class="font-mono" :class="r.amount >= 0 ? '' : 'text-danger-600'">{{ fmtRp(r.amount) }}</span></div>
                        <div v-if="!direct[grp.k].length" class="py-0.5 text-neutral-400">—</div>
                        <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Subtotal</span><span class="font-mono">{{ fmtRp(direct['total_'+grp.k]) }}</span></div>
                    </div>
                    <div v-if="directTagged.length" class="rounded-lg border border-primary-200 bg-primary-50/40 p-3 dark:border-primary-900/40 dark:bg-primary-900/10">
                        <div class="mb-1 text-xs font-semibold uppercase text-primary-600">Dari Tag Mutasi Ledger (per Kategori)</div>
                        <div v-for="t in directTagged" :key="t.name" class="flex justify-between py-0.5 text-xs"><span>{{ t.name }} <span class="text-neutral-400">({{ ACT[t.activity] ?? t.activity }})</span></span><span class="font-mono">{{ fmtRp(t.amount) }}</span></div>
                    </div>
                    <div class="flex justify-between rounded-lg bg-primary-50 px-3 py-2 font-bold dark:bg-primary-900/20"><span>Kenaikan/(Penurunan) Kas</span><span class="font-mono">{{ fmtRp(direct.net_change) }}</span></div>
                </div>
            </div>

            <!-- INDIRECT -->
            <div v-if="showIndirect" class="card p-5">
                <h2 class="mb-3 font-semibold">Metode Tidak Langsung (Indirect)</h2>
                <div class="space-y-1 text-sm">
                    <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">Arus Kas Operasi</div>
                    <div class="flex justify-between py-0.5"><span>Laba/(Rugi) Bersih</span><span class="font-mono">{{ fmtRp(indirect.net_profit) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Penyusutan</span><span class="font-mono">{{ fmtRp(indirect.depreciation) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(−) Kenaikan Piutang</span><span class="font-mono">{{ fmtRp(-indirect.d_receivable) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(−) Kenaikan Persediaan/CIP</span><span class="font-mono">{{ fmtRp(-indirect.d_inventory) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Kenaikan Hutang Usaha</span><span class="font-mono">{{ fmtRp(indirect.d_payable) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Kenaikan Hutang Pajak</span><span class="font-mono">{{ fmtRp(indirect.d_tax_payable) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Kas dari Operasi</span><span class="font-mono">{{ fmtRp(indirect.cash_from_ops) }}</span></div>
                    <div class="flex justify-between py-0.5 pt-2"><span class="text-xs font-semibold uppercase text-neutral-400">Investasi</span><span class="font-mono">{{ fmtRp(indirect.investing) }}</span></div>
                    <div class="flex justify-between py-0.5"><span class="text-xs font-semibold uppercase text-neutral-400">Pendanaan</span><span class="font-mono">{{ fmtRp(indirect.financing) }}</span></div>
                    <div v-if="indirectTagged.length" class="mt-2 rounded-lg border border-primary-200 bg-primary-50/40 p-3 dark:border-primary-900/40 dark:bg-primary-900/10">
                        <div class="mb-1 text-xs font-semibold uppercase text-primary-600">Dari Tag Mutasi Ledger (per Kategori)</div>
                        <div v-for="t in indirectTagged" :key="t.name" class="flex justify-between py-0.5 text-xs"><span>{{ t.name }} <span class="text-neutral-400">({{ ACT[t.activity] ?? t.activity }})</span></span><span class="font-mono">{{ fmtRp(t.amount) }}</span></div>
                    </div>
                    <div class="flex justify-between rounded-lg bg-neutral-50 px-3 py-2 font-bold dark:bg-neutral-800"><span>Kenaikan/(Penurunan) Kas</span><span class="font-mono">{{ fmtRp(indirect.net_change) }}</span></div>
                </div>
            </div>
        </div>
    </div>
</template>
