<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, Wallet, Send, CheckCircle2, Search, Upload, Ban } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    cheques: any;
    types: Record<string, string>;
    statuses: Record<string, string>;
    bankAccounts: any[];
    filters: { status?: string; search?: string; type?: string; bank_account_id?: number | string };
    summary: { dimiliki: { count: number; total: number }; beredar: { count: number; total: number }; dicairkan: { count: number; total: number }; void: { count: number; total: number } };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const type = ref<string>(props.filters.type ?? '');
const bankId = ref<number | string>(props.filters.bank_account_id ?? '');

// #170 opsi rekening bank untuk dropdown filter.
const bankOptions = computed(() => (props.bankAccounts ?? []).map((b: any) => ({ id: b.id, code: b.code, name: b.name, label: `${b.code} — ${b.name}` })));

// #170 tab pemisah CEK vs BG (selain "semua").
const typeTabs = computed(() => [{ value: '', label: 'Semua' }, ...Object.entries(props.types).map(([val, label]) => ({ value: val, label }))]);

function applyFilter() {
    router.get(route('bs.cheque-out.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        type: type.value || undefined,
        bank_account_id: bankId.value || undefined,
    }, { preserveState: true, replace: true });
}
function setType(v: string) { type.value = v; applyFilter(); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const badge: Record<string, any> = { available: 'neutral', issued: 'warning', cleared: 'success', void: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> BG/Cek Keluar <span class="text-base font-normal text-neutral-400">(Arus Keluar Perusahaan)</span></h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.cheque-out.import.form')"><Button variant="secondary"><template #icon-left><Upload class="h-4 w-4" /></template>Import Data</Button></Link>
                <Link :href="route('bs.cheque-out.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambah BG/Cek</Button></Link>
            </div>
        </div>

        <!-- #55/#68/#170: dashboard dimiliki (jumlah saja) / beredar / dicairkan / void -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- #170(c) status "dimiliki" hanya menampilkan jumlah lembar, tanpa nominal rupiah. -->
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Wallet class="h-4 w-4" /> Dimiliki (belum diserahkan)</div><div class="mt-1 text-xl font-bold">{{ summary.dimiliki.count }} lembar</div><div class="text-xs text-neutral-400">Jumlah BG/Cek tersedia</div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Send class="h-4 w-4 text-amber-600" /> Beredar</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(summary.beredar.total) }}</div><div class="text-xs text-neutral-400">{{ summary.beredar.count }} lembar</div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><CheckCircle2 class="h-4 w-4 text-success-600" /> Dicairkan</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(summary.dicairkan.total) }}</div><div class="text-xs text-neutral-400">{{ summary.dicairkan.count }} lembar</div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Ban class="h-4 w-4 text-danger-600" /> Void (dibatalkan)</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(summary.void.total) }}</div><div class="text-xs text-neutral-400">{{ summary.void.count }} lembar</div></div>
        </div>

        <!-- #170(b) tab pemisah jenis: Semua / Cek / Bilyet Giro -->
        <div class="flex flex-wrap items-center gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button v-for="t in typeTabs" :key="t.value || 'all'" type="button" @click="setType(t.value)"
                :class="['-mb-px border-b-2 px-4 py-2 text-sm font-medium transition', type === t.value ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200']">
                {{ t.label }}
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. BG/Cek / penerima…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <!-- #170(a) filter rekening bank -->
            <div class="w-64">
                <SearchSelect v-model="bankId" :options="bankOptions" placeholder="Semua bank" @update:model-value="applyFilter" />
            </div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua status</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. BG/Cek</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Bank</th><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Tgl BG/Cek</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in cheques.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.cheque-out.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.cheque_number }}</td>
                        <td class="px-4 py-2.5">{{ types[r.cheque_type] }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.bank_account?.name }}</td>
                        <td class="px-4 py-2.5">{{ r.payee ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.cheque_date ? fmtDate(r.cheque_date) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.amount != null ? fmtRp(r.amount) : '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="badge[r.status] ?? 'neutral'" size="sm">{{ statuses[r.status] }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!cheques.data?.length" title="Belum ada BG/Cek keluar" description="Klik Tambah BG/Cek untuk mencatat warkat yang diterbitkan ke supplier/vendor." />
        </div>
        <Pagination :data="cheques" />
    </div>
</template>
