<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';

type Can = { edit: boolean; submit: boolean; cancel: boolean; close: boolean; delete: boolean; invoice: boolean };
type Row = {
    id: number; number: string; date: string | null; document_date: string | null; needed_date: string | null; supplier: string | null; supplier_invoice_no: string | null;
    amount: number | null; description: string; status: string; deleted: boolean; dept_head: string | null; uploader: string | null;
    files_count: number; reason: string | null; can: Can;
};

const props = defineProps<{
    tab: string;
    tabs: Record<string, string>;
    tabCounts: Record<string, number>;
    statusCounts: Record<string, number>;
    statusLabels: Record<string, string>;
    suppliers: Array<{ id: number; name: string }>;
    filters: Record<string, string | undefined>;
    rows: { data: Row[]; links: any[]; from: number | null; to: number | null; total: number };
    can: { upload: boolean; export: boolean; trashed: boolean };
}>();

// Filter — langsung diterapkan (teks dengan jeda singkat), ikut terbawa ke halaman & export.
const f = reactive({
    q: props.filters.q ?? '', status: props.filters.status ?? '', supplier_id: props.filters.supplier_id ?? '',
    from: props.filters.from ?? '', to: props.filters.to ?? '', trashed: props.filters.trashed ?? '',
});
const params = () => Object.fromEntries(Object.entries({ tab: props.tab, ...f }).filter(([, v]) => v !== '' && v != null));
const muat = () => router.get(route('bs.ap.berkas.index'), params(), { preserveState: true, preserveScroll: true, replace: true });
let jeda: ReturnType<typeof setTimeout> | undefined;
watch(() => f.q, () => { clearTimeout(jeda); jeda = setTimeout(muat, 350); });
watch(() => [f.status, f.supplier_id, f.from, f.to, f.trashed], muat);

const keTab = (tab: string) => router.get(route('bs.ap.berkas.index'), { tab }, { preserveState: false });
const reset = () => { Object.assign(f, { q: '', status: '', supplier_id: '', from: '', to: '', trashed: '' }); };
const exportUrl = (format: 'xlsx' | 'pdf') => route('bs.ap.berkas.export', { ...params(), format });

// Batal (alasan wajib) — dialog di halaman, bukan confirm() browser.
const dialog = ref<{ row: Row; mode: 'cancel' | 'delete' } | null>(null);
const alasan = ref('');
function konfirmasi() {
    if (!dialog.value) return;
    const { row, mode } = dialog.value;
    const done = { preserveScroll: true, onSuccess: () => { dialog.value = null; alasan.value = ''; } };
    if (mode === 'cancel') router.post(route('bs.ap.berkas.cancel', row.id), { reason: alasan.value }, done);
    else router.delete(route('bs.ap.berkas.destroy', row.id), done);
}
const ajukan = (row: Row) => router.post(route('bs.ap.berkas.submit', row.id), {}, { preserveScroll: true });

const variant: Record<string, string> = {
    draft: 'neutral', menunggu_ttd: 'warning', siap_invoice: 'info', sebagian_diinvoice: 'info',
    diinvoice: 'success', ditolak: 'danger', dibatalkan: 'neutral', ditutup: 'neutral',
};
const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
const urutStatus = ['draft', 'menunggu_ttd', 'siap_invoice', 'sebagian_diinvoice', 'diinvoice', 'ditolak', 'dibatalkan', 'ditutup'];
</script>

<template>
    <Head title="Upload Berkas" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Upload Berkas</h1>
                <p class="text-sm text-neutral-500">Berkas tagihan → TTD Dept Head → Invoice Hutang → approval Accounting s.d. MW / BA → bayar.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <template v-if="can.export">
                    <a :href="exportUrl('xlsx')"><Button variant="outline" size="sm">Export Excel</Button></a>
                    <a :href="exportUrl('pdf')" target="_blank" rel="noopener"><Button variant="outline" size="sm">Cetak / PDF</Button></a>
                </template>
                <Link v-if="can.upload" :href="route('bs.ap.berkas.create')"><Button>+ Unggah Berkas</Button></Link>
            </div>
        </div>

        <!-- Tab cakupan -->
        <div class="flex gap-2 overflow-x-auto border-b border-neutral-200 dark:border-neutral-800">
            <button
                v-for="(label, key) in tabs" :key="key" @click="keTab(String(key))"
                :class="['whitespace-nowrap px-4 py-2 text-sm font-medium', props.tab === key ? 'border-b-2 border-primary-600 text-primary-600' : 'text-neutral-500 hover:text-neutral-700']"
            >
                {{ label }} <span class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs dark:bg-neutral-800">{{ tabCounts[key] ?? 0 }}</span>
            </button>
        </div>

        <!-- Chip status -->
        <div class="flex flex-wrap gap-2">
            <button :class="['rounded-full border px-3 py-1 text-xs font-medium', !f.status ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40' : 'border-neutral-200 text-neutral-600 dark:border-neutral-700']" @click="f.status = ''">
                Semua status
            </button>
            <button
                v-for="s in urutStatus.filter((s) => statusCounts[s])" :key="s" @click="f.status = s"
                :class="['rounded-full border px-3 py-1 text-xs font-medium', f.status === s ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40' : 'border-neutral-200 text-neutral-600 dark:border-neutral-700']"
            >
                {{ statusLabels[s] }} <span class="text-neutral-400">{{ statusCounts[s] }}</span>
            </button>
        </div>

        <!-- Filter -->
        <div class="card grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-6">
            <input id="f-q" v-model="f.q" type="search" class="input-base sm:col-span-2" placeholder="Cari no. berkas, keterangan, supplier, no. tagihan, pengunggah…" />
            <select id="f-supplier" v-model="f.supplier_id" class="input-base">
                <option value="">Semua supplier</option>
                <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            </select>
            <div class="flex min-w-0 items-center gap-1 sm:col-span-2 xl:col-span-3">
                <input id="f-from" v-model="f.from" type="date" class="input-base min-w-0 flex-1" aria-label="Dari tanggal" />
                <span class="text-neutral-400">–</span>
                <input id="f-to" v-model="f.to" type="date" class="input-base min-w-0 flex-1" aria-label="Sampai tanggal" />
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm sm:col-span-2 xl:col-span-6">
                <label v-if="can.trashed" class="flex items-center gap-2 text-neutral-600"><input type="checkbox" :checked="!!f.trashed" @change="f.trashed = ($event.target as HTMLInputElement).checked ? '1' : ''" /> Tampilkan yang dihapus</label>
                <button class="text-primary-600 hover:underline" @click="reset">Reset filter</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. Berkas</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Diunggah</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" :class="['hover:bg-neutral-50 dark:hover:bg-neutral-800/50', r.deleted && 'opacity-60']">
                        <td class="px-4 py-3">
                            <p class="font-mono text-xs font-semibold">{{ r.number }}</p>
                            <p class="text-xs text-neutral-500">Jatuh tempo: {{ r.needed_date ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ r.description }}</p>
                            <p class="text-xs text-neutral-500">{{ r.supplier ?? 'Supplier belum dipilih' }}<template v-if="r.supplier_invoice_no"> · {{ r.supplier_invoice_no }}</template> · 📎 {{ r.files_count }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ r.amount != null ? rupiah(r.amount) : '—' }}</td>
                        <td class="px-4 py-3">
                            <p>{{ r.uploader }}</p>
                            <p class="text-xs text-neutral-500">{{ r.date }} · TTD: {{ r.dept_head }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="(variant[r.status] as any) ?? 'neutral'" dot>{{ statusLabels[r.status] ?? r.status }}</Badge>
                            <Badge v-if="r.deleted" variant="danger" class="ml-1">Dihapus</Badge>
                            <p v-if="r.reason" class="mt-1 max-w-[16rem] truncate text-xs text-neutral-500" :title="r.reason">ⓘ {{ r.reason }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <div class="inline-flex flex-wrap justify-end gap-x-3 gap-y-1">
                                <Link v-if="r.can.invoice" :href="route('bs.ap.create', { berkas: r.id })" class="font-medium text-primary-600 hover:underline">Buat Invoice</Link>
                                <Link v-if="r.can.edit" :href="route('bs.ap.berkas.edit', r.id)" class="text-primary-600 hover:underline">{{ r.status === 'ditolak' ? 'Revisi' : 'Edit' }}</Link>
                                <button v-if="r.can.submit" class="text-primary-600 hover:underline" @click="ajukan(r)">{{ r.status === 'ditolak' ? 'Ajukan ulang' : 'Ajukan' }}</button>
                                <button v-if="r.can.cancel" class="text-amber-700 hover:underline" @click="dialog = { row: r, mode: 'cancel' }">Batal</button>
                                <button v-if="r.can.delete" class="text-red-600 hover:underline" @click="dialog = { row: r, mode: 'delete' }">Hapus</button>
                                <Link :href="route('bs.ap.berkas.show', r.id)" class="text-neutral-500 hover:underline">Detail</Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data.length" title="Tidak ada berkas yang cocok dengan filter." />
        </div>
        <Pagination :data="rows" satuan="berkas" />

        <!-- Dialog batal / hapus -->
        <div v-if="dialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="dialog = null">
            <div class="card w-full max-w-md space-y-3 p-5">
                <p class="font-semibold">{{ dialog.mode === 'cancel' ? 'Batalkan' : 'Hapus' }} berkas {{ dialog.row.number }}?</p>
                <template v-if="dialog.mode === 'cancel'">
                    <label class="label-base" for="alasan-batal">Alasan batal <span class="text-red-500">*</span></label>
                    <textarea id="alasan-batal" v-model="alasan" rows="3" class="input-base h-auto py-2" placeholder="mis. Tagihan dobel, supplier membatalkan" />
                </template>
                <p v-else class="text-sm text-neutral-600">Berkas yang dihapus tetap bisa dilihat oleh tim AP lewat "Tampilkan yang dihapus".</p>
                <div class="flex justify-end gap-2">
                    <Button variant="secondary" @click="dialog = null">Kembali</Button>
                    <Button :variant="dialog.mode === 'cancel' ? 'warning' : 'danger'" :disabled="dialog.mode === 'cancel' && !alasan.trim()" @click="konfirmasi">
                        {{ dialog.mode === 'cancel' ? 'Batalkan berkas' : 'Hapus berkas' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
