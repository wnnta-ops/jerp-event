<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref, computed } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { Plus, ClipboardList, CalendarRange, RotateCcw, PenLine } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    sips: any;
    statuses: Record<string, string>;
    filters: { status?: string | null; date_from?: string | null; date_to?: string | null };
    summary: { pending: number; approved: number; total: number };
    ttd: Record<string, string>;
    signers: Record<string, string>;
    canSetupTtd: boolean;
}>();

// Setup TTD SIP — nama di bawah tanda tangan cetak SIP (izin bs.sip.ttd-setup).
const kelompokTtd: Record<string, string> = {
    staf_finance: 'Dibuat', spv_finance: 'Diperiksa',
    manager_finance: 'Diketahui', gm: 'Diketahui', direksi: 'Diketahui',
    moch_winarto: 'Disetujui', boy_arifin: 'Disetujui',
};
const showTtd = ref(false);
const ttdForm = useForm({ ttd: { ...props.ttd } });
function bukaTtd() { ttdForm.ttd = { ...props.ttd }; ttdForm.clearErrors(); showTtd.value = true; }
function simpanTtd() {
    ttdForm.put(route('bs.sip.ttd'), { preserveScroll: true, onSuccess: () => { showTtd.value = false; } });
}

// Filter PERIODE Tanggal SIP — menggantikan kolom pencarian teks.
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const status = ref(props.filters.status ?? '');
const adaFilter = computed(() => Boolean(dateFrom.value || dateTo.value || status.value));

function applyFilter() {
    router.get(route('bs.sip.index'), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
}
function resetFilter() { dateFrom.value = ''; dateTo.value = ''; status.value = ''; applyFilter(); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const badge: Record<string, any> = { submitted: 'info', printed: 'warning', approved: 'success', cancelled: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> SIP <span class="text-base font-normal text-neutral-400">(Standing Instruction Payment)</span></h1>
            <div class="flex items-center gap-2">
                <button v-if="canSetupTtd" type="button" @click="bukaTtd"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    <PenLine class="h-4 w-4" /> Setup TTD
                </button>
                <Link :href="route('bs.sip.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Buat SIP</Button></Link>
            </div>
        </div>

        <!-- Setup TTD SIP: nama yang tercetak di bawah tanda tangan cetak SIP. -->
        <div v-if="showTtd" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showTtd = false">
            <form class="card w-full max-w-lg space-y-3 p-5" @submit.prevent="simpanTtd">
                <div>
                    <h2 class="font-semibold">Setup TTD SIP</h2>
                    <p class="text-xs text-neutral-500">Nama di bawah tanda tangan cetak SIP — boleh nama lengkap atau inisial. Berlaku untuk cetakan berikutnya.</p>
                </div>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-neutral-500"><th class="py-1 pr-2">Kelompok</th><th class="py-1 pr-2">Slot</th><th class="py-1">Nama TTD</th></tr></thead>
                    <tbody>
                        <tr v-for="(label, slot) in signers" :key="slot">
                            <td class="py-1 pr-2 text-neutral-500">{{ kelompokTtd[slot] }}</td>
                            <td class="py-1 pr-2">{{ label }}</td>
                            <td class="py-1">
                                <input v-model="ttdForm.ttd[slot]" type="text" maxlength="60" required
                                    class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                <div v-if="(ttdForm.errors as any)['ttd.' + slot]" class="text-xs text-danger-600">{{ (ttdForm.errors as any)['ttd.' + slot] }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="showTtd = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                    <Button type="submit" :disabled="ttdForm.processing">Simpan</Button>
                </div>
            </form>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum Disetujui</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.pending }}</div><div class="text-[11px] text-neutral-400">Diajukan / Dicetak</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Disetujui</div><div class="mt-1 text-xl font-bold text-success-600">{{ summary.approved }}</div><div class="text-[11px] text-neutral-400">semua pihak TTD</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Nilai SIP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(summary.total) }}</div></div>
        </div>

        <!-- Filter periode Tanggal SIP: periode awal → periode akhir. -->
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex items-end gap-2 rounded-lg border border-neutral-200 bg-white px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                <CalendarRange class="mb-2 h-4 w-4 shrink-0 text-neutral-400" />
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-neutral-500">Periode Awal</label>
                    <input v-model="dateFrom" type="date" :max="dateTo || undefined" @change="applyFilter"
                        class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <span class="mb-2 text-neutral-400">—</span>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-neutral-500">Periode Akhir</label>
                    <input v-model="dateTo" type="date" :min="dateFrom || undefined" @change="applyFilter"
                        class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
            </div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua status</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option>
            </select>
            <button v-if="adaFilter" type="button" @click="resetFilter"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                <RotateCcw class="h-3.5 w-3.5" /> Reset Filter
            </button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. SIP</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Bank Sumber</th><th class="px-4 py-3">Judul</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in sips.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.sip.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.sip_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.sip_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.cash_account?.name }}</td>
                        <td class="px-4 py-2.5">{{ r.title ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="badge[r.status] ?? 'neutral'" size="sm">{{ statuses[r.status] }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <!-- Kosong karena filter ≠ kosong karena belum ada data — jangan sampai tertukar. -->
            <EmptyState v-if="!sips.data?.length && adaFilter" title="Tidak ada SIP pada periode ini" description="Ubah periode awal / akhir atau status, atau klik Reset Filter." />
            <EmptyState v-else-if="!sips.data?.length" title="Belum ada SIP" description="Klik Buat SIP untuk mengajukan instruksi pembayaran tagihan & bon per bank sumber." />
        </div>
        <Pagination :data="sips" />
    </div>
</template>
