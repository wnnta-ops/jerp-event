<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calculator, Layers } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ projects: any[]; cipAccounts: any[]; filters: any; result: any | null }>();

const f = ref({
    project_id: props.filters.project_id ?? '',
    cip_account_id: props.filters.cip_account_id ?? '',
    basis: props.filters.basis ?? 'equal',
    as_of: props.filters.as_of ?? new Date().toISOString().slice(0, 10),
});
function generate() { router.get(route('bs.cogs.realcost'), { ...f.value }, { preserveState: true, replace: true }); }

const today = new Date().toISOString().slice(0, 10);
const selected = ref<number[]>([]);
const eligibleUnits = computed(() => (props.result?.units ?? []).filter((u: any) => u.eligible));
const allSelected = computed(() => eligibleUnits.value.length > 0 && selected.value.length === eligibleUnits.value.length);
function toggleAll() { selected.value = allSelected.value ? [] : eligibleUnits.value.map((u: any) => u.unit_id); }
const selectedTotal = computed(() => (props.result?.units ?? []).filter((u: any) => selected.value.includes(u.unit_id)).reduce((s: number, u: any) => s + Number(u.alloc), 0));

const postForm = useForm<any>({ project_id: '', cip_account_id: '', basis: 'equal', recognition_date: today, unit_ids: [] as number[] });
function postHpp() {
    if (!selected.value.length) return;
    postForm.project_id = f.value.project_id; postForm.cip_account_id = f.value.cip_account_id; postForm.basis = f.value.basis;
    postForm.unit_ids = selected.value;
    if (confirm(`Posting HPP RealCost untuk ${selected.value.length} unit (${fmtRp(selectedTotal.value)})?`)) postForm.post(route('bs.cogs.realcost.post'), { onSuccess: () => { selected.value = []; } });
}

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.cogs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> COGS (HPP)</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> HPP RealCost (per Unit)</h1>

        <div class="card grid items-end gap-3 p-4 md:grid-cols-5">
            <div class="md:col-span-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Proyek</label><select v-model="f.project_id" :class="inputCls"><option value="">— pilih —</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun CIP / Persediaan (pool)</label><SearchSelect v-model="f.cip_account_id" :options="cipAccounts" placeholder="— cari CIP —" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dasar Alokasi</label><select v-model="f.basis" :class="inputCls"><option value="equal">Rata per unit</option><option value="price">Proporsi harga</option></select></div>
            <div class="flex gap-2"><div class="flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Per Tgl</label><input v-model="f.as_of" type="date" :class="inputCls" /></div><button @click="generate" class="h-9 self-end rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Generate</button></div>
        </div>

        <template v-if="result">
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pool Biaya (CIP)</div><div class="mt-1 text-base font-bold">{{ fmtRp(result.pool) }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Unit Terjual</div><div class="mt-1 text-base font-bold text-primary-600">{{ result.sold_count }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sudah Diakui</div><div class="mt-1 text-base font-bold">{{ result.recognized_count }}</div></div>
                <div class="card p-4"><div class="text-xs uppercase text-neutral-400">HPP Layak Posting</div><div class="mt-1 text-base font-bold text-success-700">{{ fmtRp(result.eligible_total) }}</div></div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <span class="font-semibold">Alokasi Biaya Riil per Unit</span>
                    <div v-if="eligibleUnits.length" class="flex items-center gap-3">
                        <span class="text-sm text-neutral-500">Dipilih: {{ selected.length }} · {{ fmtRp(selectedTotal) }}</span>
                        <Button size="sm" :disabled="!selected.length || postForm.processing" @click="postHpp"><template #icon-left><Layers class="h-4 w-4" /></template>Posting HPP</Button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-2.5 w-8"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded" :disabled="!eligibleUnits.length" /></th>
                        <th class="px-4 py-2.5">Unit</th><th class="px-4 py-2.5 text-right">Dasar</th><th class="px-4 py-2.5 text-right">Biaya Riil (alokasi)</th><th class="px-4 py-2.5 text-center">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="u in result.units" :key="u.unit_id" :class="u.eligible ? '' : 'opacity-60'">
                            <td class="px-4 py-2"><input v-if="u.eligible" type="checkbox" :value="u.unit_id" v-model="selected" class="rounded" /></td>
                            <td class="px-4 py-2 font-mono">{{ u.code }}</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-500">{{ Number(u.basis).toLocaleString('id-ID') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ fmtRp(u.alloc) }}</td>
                            <td class="px-4 py-2 text-center">
                                <Badge v-if="u.recognized" variant="success" size="sm">Diakui</Badge>
                                <Badge v-else-if="u.sold" variant="warning" size="sm">Terjual</Badge>
                                <Badge v-else variant="neutral" size="sm">Belum jual</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="px-4 py-3 text-xs text-neutral-400">Pool dialokasikan ke {{ result.units.length }} unit (dasar: {{ f.basis === 'price' ? 'proporsi harga' : 'rata per unit' }}). Posting hanya untuk unit <b>terjual &amp; belum diakui</b>. Jurnal: Dr Beban Pokok Penjualan · Cr CIP/Persediaan.</p>
            </div>
        </template>
        <div v-else class="card p-8 text-center text-sm text-neutral-400">Pilih proyek &amp; akun CIP, lalu klik Generate untuk menghitung HPP RealCost per unit.</div>
    </div>
</template>
