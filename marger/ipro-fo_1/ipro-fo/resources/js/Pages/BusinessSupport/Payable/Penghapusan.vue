<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Eraser, Search, FileBarChart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    invoices: Array<any>;
    filters: { search?: string };
    counterAccounts: Array<any>;
    defaultCounter: number | null;
}>();

const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('bs.ap.penghapusan.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

const activeId = ref<number | null>(null);
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ counter_account_id: props.defaultCounter ?? '', amount: 0, memorial_date: today, description: '' });
function openWriteoff(inv: any) { activeId.value = inv.id; form.reset(); form.counter_account_id = props.defaultCounter ?? ''; form.memorial_date = today; form.amount = inv.outstanding; form.description = 'Penghapusan sisa hutang ' + inv.invoice_number; }
function submit(id: number) { form.post(route('bs.ap.penghapusan.store', id), { onSuccess: () => { activeId.value = null; } }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Eraser class="h-6 w-6 text-danger-600" /> Penghapusan Nilai Hutang</h1>
            <Link :href="route('bs.ap.penghapusan.report')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileBarChart class="h-4 w-4" /> Laporan Penghapusan</Link>
        </div>
        <p class="text-sm text-neutral-500">Hapus sisa hutang invoice yang tidak akan dibayar — Dr Hutang / Cr Akun Lawan (mis. pendapatan lain). Rincian &amp; history penghapusan ada di <b>Laporan Penghapusan Hutang</b>.</p>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari invoice…" :class="[inputCls,'pl-9']" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Invoice</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3 text-right">Outstanding</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="inv in invoices" :key="inv.id">
                        <tr>
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ inv.invoice_number }}</td>
                            <td class="px-4 py-2.5">{{ inv.supplier }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ inv.description }}</td>
                            <td class="px-4 py-2.5">{{ fmtDate(inv.due_date) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(inv.outstanding) }}</td>
                            <td class="px-4 py-2.5 text-right"><button @click="openWriteoff(inv)" class="text-xs text-danger-600 hover:underline">Hapus Nilai</button></td>
                        </tr>
                        <tr v-if="activeId === inv.id">
                            <td colspan="6" class="bg-neutral-50 px-4 py-3 dark:bg-neutral-800/50">
                                <form @submit.prevent="submit(inv.id)" class="flex flex-wrap items-end gap-3">
                                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal</label><input v-model="form.memorial_date" type="date" required :class="[inputCls,'w-40']" /></div>
                                    <div class="w-56"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Lawan</label><SearchSelect v-model="form.counter_account_id" :options="counterAccounts" placeholder="— cari akun (kode / nama) —" /></div>
                                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai</label><input v-model.number="form.amount" type="number" step="1" min="1" :max="inv.outstanding" required :class="[inputCls,'w-40 text-right']" /></div>
                                    <div class="flex-1 min-w-48"><label class="mb-1 block text-xs font-medium text-neutral-500">Alasan Penghapusan</label><input v-model="form.description" required placeholder="mis. supplier membebaskan sisa, selisih pembulatan, dll." :class="inputCls" /></div>
                                    <Button type="submit" size="sm" :disabled="form.processing"><template #icon-left><Eraser class="h-4 w-4" /></template>Hapus</Button>
                                    <button type="button" @click="activeId = null" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">Batal</button>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!invoices.length" title="Tidak ada hutang outstanding" description="Semua invoice hutang sudah lunas." />
        </div>
    </div>
</template>
