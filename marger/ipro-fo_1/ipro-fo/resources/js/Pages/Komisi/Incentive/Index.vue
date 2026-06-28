<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Gift, Plus, Settings2, Check, X, Wallet, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    type: string; typeLabel: string;
    rows: any; filters: { status?: string }; statuses: Record<string, string>;
    stats: { diajukan: number; disetujui: number; direalisasi: number; nilai_realisasi: number };
    schemes: Array<any>;
    masters: { sales: Array<any>; agents: Array<any>; bookings: Array<any>; unitTypes: Array<any> };
}>();

const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('incentives.index', props.type), { status: status.value || undefined }, { preserveState: true, replace: true }); }

// Pengajuan
const showForm = ref(false);
const form = useForm({ beneficiary_type: 'sales', sales_person_id: null as number | null, agent_id: null as number | null, booking_id: null as number | null, scheme_id: null as number | null, description: '', amount: 0 });
// Saat skema dipilih & basis Rupiah → isi otomatis nominalnya (boleh diedit).
watch(() => form.scheme_id, (id) => {
    const s = props.schemes.find((x: any) => x.id === id);
    if (s && s.basis === 'amount' && (!form.amount || form.amount === 0)) form.amount = Number(s.value) || 0;
});
// Daftar penerima kosong → beri tahu agar user tahu harus isi master Sales/Agen dulu.
const beneficiaryEmpty = computed(() => form.beneficiary_type === 'sales' ? !props.masters.sales.length : !props.masters.agents.length);
function submitPengajuan() { form.post(route('incentives.store', props.type), { onSuccess: () => { showForm.value = false; form.reset(); } }); }

// Skema setup
const showSkema = ref(false);
const sform = useForm({ code: '', name: '', basis: 'amount', value: 0, unit_type_id: null as number | null, is_active: true, notes: '' });
function addSkema() { sform.post(route('incentives.scheme.store', props.type), { preserveScroll: true, onSuccess: () => sform.reset() }); }
function delSkema(s: any) { if (confirm(`Hapus skema ${s.code}?`)) router.delete(route('incentives.scheme.destroy', s.id), { preserveScroll: true }); }

// Aksi lifecycle
function approve(r: any) { router.post(route('incentives.approve', r.id), {}, { preserveScroll: true }); }
function realize(r: any) { if (confirm(`Realisasikan (bayarkan) ${r.incentive_no} sebesar ${rb(r.amount)}?`)) router.post(route('incentives.realize', r.id), {}, { preserveScroll: true }); }
function reject(r: any) { const reason = prompt('Alasan penolakan:'); if (reason) router.post(route('incentives.reject', r.id), { reject_reason: reason }, { preserveScroll: true }); }

const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const sv: Record<string, any> = { diajukan: 'warning', disetujui: 'primary', ditolak: 'danger', direalisasi: 'success' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Gift class="h-6 w-6 text-accent-600" /> Insentif {{ typeLabel }}</h1>
            <div class="flex gap-2">
                <button @click="showSkema = !showSkema" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Settings2 class="h-4 w-4" /> Setup Skema</button>
                <button @click="showForm = !showForm" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Plus class="h-4 w-4" /> Pengajuan Baru</button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Diajukan</div><div class="mt-1 text-xl font-bold text-amber-600">{{ stats.diajukan }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Disetujui</div><div class="mt-1 text-xl font-bold text-primary-600">{{ stats.disetujui }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Direalisasi</div><div class="mt-1 text-xl font-bold text-success-600">{{ stats.direalisasi }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Realisasi</div><div class="mt-1 text-lg font-bold">{{ rb(stats.nilai_realisasi) }}</div></div>
        </div>

        <!-- Setup Skema -->
        <div v-if="showSkema" class="card p-4">
            <div class="mb-3 text-sm font-semibold">Setup Skema {{ typeLabel }}</div>
            <form @submit.prevent="addSkema" class="grid gap-2 sm:grid-cols-6">
                <input v-model="sform.code" :class="inputCls" placeholder="Kode *" required />
                <input v-model="sform.name" :class="inputCls + ' sm:col-span-2'" placeholder="Nama *" required />
                <select v-model="sform.basis" :class="inputCls"><option value="amount">Rupiah</option><option value="percent">Persen</option></select>
                <input type="number" step="0.01" v-model.number="sform.value" :class="inputCls" placeholder="Nilai" />
                <button type="submit" :disabled="sform.processing" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Tambah</button>
            </form>
            <table class="mt-3 w-full text-sm">
                <thead class="text-xs uppercase text-neutral-500"><tr class="text-left"><th class="px-2 py-1">Kode</th><th class="px-2 py-1">Nama</th><th class="px-2 py-1">Basis</th><th class="px-2 py-1 text-right">Nilai</th><th class="px-2 py-1 text-center">Aktif</th><th class="px-2 py-1"></th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="s in schemes" :key="s.id">
                        <td class="px-2 py-1 font-mono">{{ s.code }}</td><td class="px-2 py-1">{{ s.name }}</td>
                        <td class="px-2 py-1">{{ s.basis === 'percent' ? 'Persen' : 'Rupiah' }}</td>
                        <td class="px-2 py-1 text-right">{{ s.basis === 'percent' ? s.value + '%' : rb(s.value) }}</td>
                        <td class="px-2 py-1 text-center">{{ s.is_active ? '✓' : '—' }}</td>
                        <td class="px-2 py-1 text-right"><button @click="delSkema(s)" class="text-neutral-400 hover:text-danger-600"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                    <tr v-if="!schemes.length"><td colspan="6" class="px-2 py-3 text-center text-neutral-400">Belum ada skema.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Pengajuan form -->
        <form v-if="showForm" @submit.prevent="submitPengajuan" class="card grid gap-3 p-4 sm:grid-cols-3">
            <div><label :class="labelCls">Penerima</label><select v-model="form.beneficiary_type" :class="inputCls"><option value="sales">Sales</option><option value="agent">Agen</option></select></div>
            <div v-if="form.beneficiary_type === 'sales'"><label :class="labelCls">Sales *</label><select v-model="form.sales_person_id" :class="inputCls" required><option :value="null">— pilih —</option><option v-for="s in masters.sales" :key="s.id" :value="s.id">{{ s.name }}</option></select><p v-if="form.errors.sales_person_id" class="mt-1 text-xs text-danger-500">{{ form.errors.sales_person_id }}</p></div>
            <div v-else><label :class="labelCls">Agen *</label><select v-model="form.agent_id" :class="inputCls" required><option :value="null">— pilih —</option><option v-for="a in masters.agents" :key="a.id" :value="a.id">{{ a.name }}</option></select><p v-if="form.errors.agent_id" class="mt-1 text-xs text-danger-500">{{ form.errors.agent_id }}</p></div>
            <div><label :class="labelCls">Skema</label><select v-model="form.scheme_id" :class="inputCls"><option :value="null">—</option><option v-for="s in schemes" :key="s.id" :value="s.id">{{ s.code }} — {{ s.name }}</option></select></div>
            <div><label :class="labelCls">Booking (opsional)</label><select v-model="form.booking_id" :class="inputCls"><option :value="null">—</option><option v-for="b in masters.bookings" :key="b.id" :value="b.id">{{ b.label }}</option></select></div>
            <div><label :class="labelCls">Nilai (Rp) *</label><input type="number" v-model.number="form.amount" :class="inputCls" required /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
            <div><label :class="labelCls">Keterangan</label><input v-model="form.description" :class="inputCls" /></div>
            <p v-if="beneficiaryEmpty" class="sm:col-span-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Belum ada data {{ form.beneficiary_type === 'sales' ? 'Sales' : 'Agen' }}. Tambahkan dulu di menu <b>Setup Sales</b> / master Agen sebelum mengajukan insentif.</p>
            <div class="sm:col-span-3 flex justify-end gap-2"><button type="button" @click="showForm = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><button type="submit" :disabled="form.processing || beneficiaryEmpty" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Ajukan</button></div>
        </form>

        <!-- Filter + tabel pengajuan -->
        <div class="flex items-center gap-2">
            <select v-model="status" @change="applyFilter" :class="inputCls + ' max-w-xs'"><option value="">Semua status</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">No</th><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Skema/Ket.</th><th class="px-4 py-3">Booking</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono text-xs">{{ r.incentive_no }}</td>
                        <td class="px-4 py-2">{{ r.beneficiary ?? '—' }} <span class="text-xs text-neutral-400">({{ r.beneficiary_type }})</span></td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.scheme ?? r.description ?? '—' }}<div v-if="r.reject_reason" class="text-xs text-danger-500">Tolak: {{ r.reject_reason }}</div></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ r.booking ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">{{ rb(r.amount) }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="sv[r.status] ?? 'neutral'" size="sm">{{ statuses[r.status] ?? r.status }}</Badge></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <template v-if="r.status === 'diajukan'">
                                <button @click="approve(r)" class="mr-1 inline-flex items-center gap-1 rounded border border-success-300 px-2 py-1 text-xs text-success-700 hover:bg-success-50"><Check class="h-3 w-3" /> Setujui</button>
                                <button @click="reject(r)" class="inline-flex items-center gap-1 rounded border border-danger-300 px-2 py-1 text-xs text-danger-600 hover:bg-danger-50"><X class="h-3 w-3" /> Tolak</button>
                            </template>
                            <button v-else-if="r.status === 'disetujui'" @click="realize(r)" class="inline-flex items-center gap-1 rounded bg-success-600 px-2 py-1 text-xs font-semibold text-white hover:bg-success-700"><Wallet class="h-3 w-3" /> Realisasi</button>
                            <span v-else class="text-xs text-neutral-400">—</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Belum ada pengajuan {{ typeLabel }}.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <a v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
