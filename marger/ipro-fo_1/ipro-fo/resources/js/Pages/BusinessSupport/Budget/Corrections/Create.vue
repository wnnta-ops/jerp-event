<!-- Koreksi Budget Baru -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    year: number;
    budgets: Array<any>;
    monthLabels: string[];
}>();

function fmtRp(v: number) {
    return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
}

function todayStr(): string {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

const form = useForm<{ budget_id: number | ''; corr_date: string; months: Record<number, number>; note: string }>({
    budget_id: '',
    corr_date: todayStr(),
    // Nilai baru per bulan (1..12) — hanya bulan yang berubah yang dikirim.
    months: {},
    note: '',
});

// Filter Cost Center (departemen) + COA — mempersempit daftar Budget master.
const fCostCenter = ref('');
const fAccount = ref('');
const costCenters = computed(() => [...new Set(props.budgets.map((b: any) => b.cost_center).filter(Boolean))].sort().map((c: any) => ({ id: c, label: c })));
const accounts = computed(() => [...new Set(props.budgets.map((b: any) => b.account).filter(Boolean))].sort().map((a: any) => ({ id: a, label: a })));
const filteredBudgets = computed(() => props.budgets.filter((b: any) =>
    (!fCostCenter.value || b.cost_center === fCostCenter.value)
    && (!fAccount.value || b.account === fAccount.value)));
// Reset pilihan bila tak lagi ada di daftar terfilter.
watch([fCostCenter, fAccount], () => {
    if (form.budget_id && !filteredBudgets.value.some((b: any) => b.id === form.budget_id)) form.budget_id = '';
});

const selected = computed(() => props.budgets.find((b: any) => b.id === form.budget_id));

/* Tabel 12 bulan seperti form edit anggaran: anggaran saat ini (sudah termasuk koreksi/alokasi
   yang berlaku), realisasi & sisa bulan itu, lalu isian nilai baru. Angkanya dari bs.budget.bulan. */
type Bulan = { anggaran: number[]; realisasi: number[]; sisa: number[] };
const bulan = ref<Bulan | null>(null);
const baru = ref<number[]>(Array(12).fill(0));
const memuat = ref(false);
watch(() => form.budget_id, async (id) => {
    bulan.value = null;
    if (!id) return;
    memuat.value = true;
    try {
        const { data } = await window.axios.get(route('bs.budget.bulan', id));
        bulan.value = data;
        baru.value = [...data.anggaran];
    } finally { memuat.value = false; }
});
const r0 = (v: any) => Math.round((Number(v) || 0) * 100) / 100;
const selisihBulan = (i: number) => (bulan.value ? r0((Number(baru.value[i]) || 0) - bulan.value.anggaran[i]) : 0);
const total = (xs: number[]) => xs.reduce((t, x) => t + (Number(x) || 0), 0);
const selisih = computed(() => baru.value.reduce((t, _, i) => t + selisihBulan(i), 0));
const adaPerubahan = computed(() => baru.value.some((_, i) => selisihBulan(i) !== 0));

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
    form.transform((d) => ({
        ...d,
        months: Object.fromEntries(baru.value.map((v, i) => [i + 1, v]).filter((_, i) => selisihBulan(i) !== 0)),
    })).post(route('bs.budget.correction.store'));
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Pencil class="h-6 w-6 text-primary-600" /> Koreksi Budget Baru</h1>
            <Link :href="route('bs.budget.correction.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Daftar Koreksi</Link>
        </div>

        <form class="card space-y-4 p-4" @submit.prevent="submit">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Filter Cost Center (Departemen)</label>
                    <SearchSelect v-model="fCostCenter" :options="costCenters" placeholder="— semua cost center —" :no-truncate="true" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Filter COA Budget</label>
                    <SearchSelect v-model="fAccount" :options="accounts" placeholder="— semua COA —" :no-truncate="true" />
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Budget master <span class="text-xs font-normal text-neutral-400">({{ filteredBudgets.length }} anggaran)</span></label>
                <select v-model="form.budget_id" :class="inputCls">
                    <option value="">— pilih —</option>
                    <option v-for="b in filteredBudgets" :key="b.id" :value="b.id">{{ b.cost_center }} — {{ b.account }} ({{ fmtRp(b.amount) }})</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Tanggal</label>
                <input v-model="form.corr_date" type="date" :class="inputCls" class="sm:!w-56" />
            </div>

            <div v-if="memuat" class="text-sm text-neutral-400">Memuat anggaran per bulan…</div>
            <div v-else-if="selected && bulan" class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Bulan</th>
                            <th class="px-3 py-2 text-right">Anggaran saat ini</th>
                            <th class="px-3 py-2 text-right">Realisasi</th>
                            <th class="px-3 py-2 text-right">Sisa</th>
                            <th class="w-48 px-3 py-2 text-right">Nilai baru</th>
                            <th class="px-3 py-2 text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(m, i) in props.monthLabels" :key="i" class="border-t border-neutral-100 dark:border-neutral-800" :class="selisihBulan(i) !== 0 ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''">
                            <td class="px-3 py-1.5 font-medium">{{ m }}</td>
                            <td class="px-3 py-1.5 text-right font-mono">{{ fmtRp(bulan.anggaran[i]) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono text-neutral-500">{{ fmtRp(bulan.realisasi[i]) }}</td>
                            <td class="px-3 py-1.5 text-right font-mono" :class="bulan.sisa[i] < 0 ? 'text-danger-600' : 'text-success-700'">{{ fmtRp(bulan.sisa[i]) }}</td>
                            <td class="px-3 py-1.5"><MoneyInput :model-value="baru[i]" @update:model-value="(v) => (baru[i] = Number(v) || 0)" class="!h-8 font-mono" /></td>
                            <td class="px-3 py-1.5 text-right font-mono" :class="selisihBulan(i) > 0 ? 'text-success-700' : (selisihBulan(i) < 0 ? 'text-danger-600' : 'text-neutral-300')">{{ selisihBulan(i) ? fmtRp(selisihBulan(i)) : '—' }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                        <tr>
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtRp(total(bulan.anggaran)) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtRp(total(bulan.realisasi)) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtRp(total(bulan.sisa)) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtRp(total(baru)) }}</td>
                            <td class="px-3 py-2 text-right font-mono" :class="selisih >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(selisih) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p v-if="selected && bulan" class="text-xs text-neutral-500">Diajukan ke Approval Inbox (Accounting → GM). Setelah disetujui, selisih tiap bulan ditambahkan ke budget bulan itu.</p>

            <div>
                <label class="mb-1 block text-sm font-medium">Catatan</label>
                <textarea v-model="form.note" rows="3" :class="inputCls" style="height:auto"></textarea>
            </div>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing || !adaPerubahan">Ajukan Koreksi</Button>
            </div>
        </form>
    </div>
</template>
