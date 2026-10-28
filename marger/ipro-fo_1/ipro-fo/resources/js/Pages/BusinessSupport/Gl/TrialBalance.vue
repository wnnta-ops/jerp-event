<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, Printer, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<{ code: string; name: string; type: string; debit: number; credit: number }>;
    total_debit: number;
    total_credit: number;
    filters: { start_date: string | null; end_date: string | null; mode: string };
}>();

const startDate = ref(props.filters?.start_date ?? '');
const endDate = ref(props.filters?.end_date ?? '');
const mode = ref(props.filters?.mode ?? 'mutation');

function filterParams() {
    return { start_date: startDate.value || undefined, end_date: endDate.value || undefined, mode: mode.value };
}
function display() {
    router.get(route('bs.gl.trialbalance'), filterParams(), { preserveState: true, replace: true, preserveScroll: true });
}
function setMode(m: string) { mode.value = m; display(); }
// Unduhan memakai filter yang SUDAH diterapkan (props), bukan isian yang belum ditekan Tampilkan.
function appliedParams() { return { start_date: props.filters?.start_date || undefined, end_date: props.filters?.end_date || undefined, mode: props.filters?.mode ?? 'mutation' }; }
function printReport() { window.open(route('bs.gl.trialbalance.print', appliedParams()), '_blank'); }
function exportExcel() { window.location.href = route('bs.gl.trialbalance.export', appliedParams()); }

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : null; }
const typeLabel: Record<string,string> = { asset:'Aktiva', liability:'Kewajiban', equity:'Ekuitas', income:'Pendapatan', expense:'Beban' };
// computed → tetap reaktif saat navigasi in-page (preserveState memakai ulang instance komponen)
const balanced = computed(() => Math.abs(props.total_debit - props.total_credit) < 0.01);

const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const segActive = 'rounded-md bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white';
const segIdle = 'rounded-md px-3 py-1.5 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white';

// Ringkasan periode dari filter yang SUDAH diterapkan (props), bukan input yang belum di-Display.
const periodText = computed(() => {
    const f = fmtDate(props.filters?.start_date ?? ''), t = fmtDate(props.filters?.end_date ?? '');
    if (f && t) return `${f} – ${t}`;
    if (f) return `sejak ${f}`;
    if (t) return `s/d ${t}`;
    return 'Semua periode';
});
const appliedModeAll = computed(() => (props.filters?.mode ?? 'mutation') === 'all');
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Neraca Saldo (Trial Balance)</h1>
            <Badge :variant="balanced ? 'success' : 'danger'" dot>{{ balanced ? 'Balance' : 'Tidak Balance' }}</Badge>
        </div>

        <!-- Filter: rentang tanggal + mode akun -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Awal</label><input v-model="startDate" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Akhir</label><input v-model="endDate" type="date" :class="inputCls" /></div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Tampilkan Akun</label>
                <div class="inline-flex items-center rounded-lg border border-neutral-300 p-0.5 dark:border-neutral-700">
                    <button type="button" @click="setMode('mutation')" :class="mode === 'mutation' ? segActive : segIdle">Akun Bermutasi</button>
                    <button type="button" @click="setMode('all')" :class="mode === 'all' ? segActive : segIdle">Semua Akun</button>
                </div>
            </div>
            <div class="flex items-end gap-2">
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
                <button @click="printReport" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Print</button>
                <button @click="exportExcel" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700"><FileSpreadsheet class="h-4 w-4" /> Export Excel</button>
            </div>
            <div class="ml-auto text-right text-xs text-neutral-500">
                <div>Periode: <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ periodText }}</span></div>
                <div>{{ appliedModeAll ? 'Semua akun' : 'Akun bermutasi' }} · {{ rows.length }} akun</div>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Akun</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.code">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2">{{ r.name }}</td>
                        <td class="px-4 py-2 text-xs text-neutral-500">{{ typeLabel[r.type] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ r.debit > 0 ? fmtRp(r.debit) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ r.credit > 0 ? fmtRp(r.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 dark:bg-neutral-800 font-bold"><tr>
                    <td colspan="3" class="px-4 py-2.5 text-right">TOTAL</td>
                    <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(total_debit) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(total_credit) }}</td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada saldo pada periode ini" description="Ubah rentang tanggal atau pilih 'Semua Akun'." />
        </div>
    </div>
</template>
