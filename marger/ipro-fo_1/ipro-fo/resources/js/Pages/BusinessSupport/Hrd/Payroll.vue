<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Banknote, Play, Users } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    runs: any;
    now: { year: number; month: number };
    activeCount: number;
    estBruto: number;
    cashAccounts: Array<any>;
    defaultCash: number | null;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

const showModal = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ period_year: props.now.year, period_month: props.now.month, run_date: today, cash_account_id: props.defaultCash ?? '', pph21_pct: 5, bpjs_pct: 1 });
function submit() { form.post(route('bs.payroll.store'), { onSuccess: () => { showModal.value = false; } }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.employees.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Employee Center</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> Payroll</h1>
            <Button @click="showModal = true" :disabled="activeCount === 0"><template #icon-left><Play class="h-4 w-4" /></template>Proses Payroll</Button>
        </div>

        <div class="card flex items-center gap-3 p-4">
            <Users class="h-6 w-6 text-primary-600" />
            <div><div class="font-semibold">{{ activeCount }} karyawan aktif</div><div class="text-sm text-neutral-500">Estimasi bruto/bulan {{ fmtRp(estBruto) }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3 text-center">Karyawan</th><th class="px-4 py-3 text-right">Bruto</th><th class="px-4 py-3 text-right">Potongan</th><th class="px-4 py-3 text-right">Net Dibayar</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in runs.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.payroll.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.run_number }}</td>
                        <td class="px-4 py-2.5">{{ months[r.period_month-1] }} {{ r.period_year }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.run_date) }}</td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total_bruto) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.total_deduction) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold text-success-700">{{ fmtRp(r.total_net) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!runs.data?.length" title="Belum ada proses payroll" description="Klik Proses Payroll untuk menggaji karyawan aktif." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-1 text-lg font-bold">Proses Payroll</h2>
                    <p class="mb-4 text-sm text-neutral-500">{{ activeCount }} karyawan aktif. Jurnal: Dr Biaya Gaji · Cr Hutang PPh21 + Potongan + Kas.</p>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun *</label><input v-model.number="form.period_year" type="number" required :class="inputCls" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bulan *</label><select v-model.number="form.period_month" required :class="inputCls"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select></div>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Bayar *</label><input v-model="form.run_date" type="date" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Kas/Bank *</label><select v-model="form.cash_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPh 21 (%)</label><input v-model.number="form.pph21_pct" type="number" step="0.5" min="0" max="100" :class="[inputCls,'text-right']" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">BPJS (%)</label><input v-model.number="form.bpjs_pct" type="number" step="0.5" min="0" max="100" :class="[inputCls,'text-right']" /></div>
                        </div>
                        <div class="flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Proses &amp; Jurnal</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
