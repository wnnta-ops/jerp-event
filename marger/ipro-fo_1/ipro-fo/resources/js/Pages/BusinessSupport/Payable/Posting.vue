<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { ArrowLeft, FileInput, ShoppingCart, HardHat } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ pos: Array<any>; progress: Array<any>; refunds: Array<any>; suppliers: Array<any> }>();

// Supplier terpilih per baris antrean (wajib dipilih sebelum Posting Invoice).
const supplierSel = ref<Record<number, number | ''>>({});
// Baris antrean tercentang untuk posting massal.
const checked = ref<Record<number, boolean>>({});
// Pencarian/filter tabel (client-side).
const q = ref('');

// Prefill supplier untuk baris yang sudah membawa supplier (mis. komponen GMC multi-supplier).
// watch immediate → jalan saat setup & tiap data di-reload (Inertia). Number() agar cocok dgn opsi.
watch(() => props.refunds, (rows) => {
    (rows ?? []).forEach((it) => { if (it.supplier_id != null) supplierSel.value[it.id] = Number(it.supplier_id); });
}, { immediate: true });

function matches(row: any, fields: string[]): boolean {
    const s = q.value.trim().toLowerCase();
    if (! s) return true;
    return fields.some((f) => String(row[f] ?? '').toLowerCase().includes(s));
}
const filteredPos = computed(() => props.pos.filter((p) => matches(p, ['po_number', 'supplier'])));
const filteredRefunds = computed(() => props.refunds.filter((it) => matches(it, ['refund_no', 'customer', 'sumber_label', 'keterangan'])));

const selectedIds = computed(() => props.refunds.filter((it) => checked.value[it.id]).map((it) => it.id));
const allChecked = computed({
    get: () => filteredRefunds.value.length > 0 && filteredRefunds.value.every((it) => checked.value[it.id]),
    set: (v: boolean) => filteredRefunds.value.forEach((it) => { checked.value[it.id] = v; }),
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function postPo(id: number) { if (confirm('Terbitkan invoice hutang dari PO ini?')) router.post(route('bs.ap.posting.po', id), {}, { preserveScroll: true }); }
function postSpk(id: number) { if (confirm('Terbitkan invoice hutang dari progress SPK ini?')) router.post(route('bs.ap.posting.spk', id), {}, { preserveScroll: true }); }
// Supplier efektif: pilihan dropdown, atau supplier bawaan baris (GMC komponen) sbg fallback.
function rowSupplier(id: number) {
    return supplierSel.value[id] || props.refunds.find((r) => r.id === id)?.supplier_id || '';
}
function postRefund(id: number) {
    const supplier_id = rowSupplier(id);
    if (! supplier_id) { alert('Pilih supplier dulu (dari Supplier Center).'); return; }
    if (confirm('Terbitkan invoice hutang dari item ini? Akan masuk ke Account Payable.'))
        router.post(route('bs.ap.posting.refund', id), { supplier_id }, { preserveScroll: true });
}
function postBulk() {
    const ids = selectedIds.value;
    if (! ids.length) return;
    const missing = ids.filter((id) => ! rowSupplier(id));
    if (missing.length) { alert(`Pilih supplier dulu untuk ${missing.length} baris terpilih.`); return; }
    if (! confirm(`Terbitkan ${ids.length} invoice hutang sekaligus? Akan masuk ke Account Payable.`)) return;
    const items = ids.map((id) => ({ id, supplier_id: rowSupplier(id) }));
    router.post(route('bs.ap.posting.bulk-refund'), { items }, { preserveScroll: true });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileInput class="h-6 w-6 text-danger-600" /> Posting Invoice</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari Purchase Order (penerimaan barang) atau progress SPK kontraktor. Hutang sudah terbukukan saat penerimaan/progress; invoice menjadi dokumen tagihan yang dibayar via Kuitansi.</p>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><ShoppingCart class="h-5 w-5 text-primary-600" /> Dari Purchase Order (penerimaan)</div>
                <div class="ml-auto flex items-center gap-2">
                    <input v-model="q" type="text" placeholder="Cari dokumen / customer / supplier / sumber..."
                        class="h-9 w-64 max-w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                    <Button v-if="selectedIds.length" size="sm" variant="warning" @click="postBulk">Posting Massal ({{ selectedIds.length }})</Button>
                </div>
            </div>
            <table v-if="pos.length || refunds.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5 w-10"><input type="checkbox" v-model="allChecked" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" title="Pilih semua" /></th><th class="px-4 py-2.5">No. Dokumen</th><th class="px-4 py-2.5">Customer</th><th class="px-4 py-2.5">Supplier</th><th class="px-4 py-2.5">Sumber</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in filteredPos" :key="'po-' + p.id">
                        <td class="px-4 py-2.5"></td>
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ p.po_number }}</td>
                        <td class="px-4 py-2.5 text-neutral-400">—</td>
                        <td class="px-4 py-2.5">{{ p.supplier ?? '—' }}</td>
                        <td class="px-4 py-2.5"><span class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">PO</span></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.received_value) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postPo(p.id)">Generate Invoice</Button></td>
                    </tr>
                    <tr v-for="it in filteredRefunds" :key="'rf-' + it.id">
                        <td class="px-4 py-2.5"><input type="checkbox" v-model="checked[it.id]" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" /></td>
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ it.refund_no }}</td>
                        <td class="px-4 py-2.5">{{ it.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <select
                                v-model="supplierSel[it.id]"
                                class="w-56 max-w-full rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <option value="">— pilih supplier —</option>
                                <option v-for="s in suppliers" :key="s.id" :value="Number(s.id)">{{ s.label }}</option>
                            </select>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">{{ it.sumber_label }}</span>
                            <div v-if="it.keterangan" class="mt-1 text-xs text-neutral-500">{{ it.keterangan }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(it.amount) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" :disabled="! (supplierSel[it.id] || it.supplier_id)" @click="postRefund(it.id)">Posting Invoice</Button></td>
                    </tr>
                    <tr v-if="! filteredPos.length && ! filteredRefunds.length">
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-500">Tidak ada hasil untuk "{{ q }}".</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada PO / refund siap diposting" description="PO dengan barang diterima, atau refund dari Finance AR yang belum diinvoice, akan tampil di sini." />
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800"><HardHat class="h-5 w-5 text-amber-600" /> Dari Progress SPK Kontraktor</div>
            <table v-if="progress.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. SPK</th><th class="px-4 py-2.5">Kontraktor</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Net Bayar</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in progress" :key="p.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ p.spk_number }}</td>
                        <td class="px-4 py-2.5">{{ p.supplier }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ p.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.net_payable) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postSpk(p.id)">Generate Invoice</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada progress SPK siap diposting" description="Progress SPK belum dibayar & belum diinvoice akan tampil di sini." />
        </div>
    </div>
</template>
