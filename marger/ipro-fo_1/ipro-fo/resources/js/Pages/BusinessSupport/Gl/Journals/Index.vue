<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, ChevronDown, ListTree, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ journals: any; sources: Record<string, string>; statuses: Record<string, string>; creators?: Array<{ id: number; name: string }>; filters: Record<string, any>; defaultDetail?: boolean; listRoute?: string; title?: string; canApprove?: boolean }>();

// Approval jurnal langsung dari daftar: hanya jurnal berstatus Final (menunggu approval) yang bisa disetujui.
const selected = ref<number[]>([]);
const finalIds = computed<number[]>(() => (props.journals?.data ?? []).filter((j: any) => j.status === 'final').map((j: any) => Number(j.id)));
const allChecked = computed({
    get: () => finalIds.value.length > 0 && finalIds.value.every((id) => selected.value.includes(id)),
    set: (v: boolean) => { selected.value = v ? [...finalIds.value] : []; },
});
function approveOne(j: any) {
    if (!confirm(`Approve jurnal ${j.journal_number}? Jurnal menjadi efektif (posted) di GL.`)) return;
    router.post(route('bs.gl.journal.approve', j.id), {}, { preserveScroll: true });
}
function bulkApprove() {
    if (!selected.value.length) return;
    if (!confirm(`Approve ${selected.value.length} jurnal terpilih? Semua menjadi efektif (posted) di GL.`)) return;
    router.post(route('bs.gl.journal.approve-batch'), { ids: selected.value }, { preserveScroll: true, onSuccess: () => { selected.value = []; } });
}
const listRoute = props.listRoute ?? 'bs.gl.journal.index';
const source = ref(props.filters.source ?? '');
const statusFilter = ref(props.filters.status ?? '');
const createdBy = ref(props.filters.created_by ?? '');
const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

function display() {
    router.get(route(listRoute), {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        created_by: createdBy.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
}
function exportExcel() {
    window.location.href = route('bs.gl.journal.export', {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        created_by: createdBy.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function fmtDateTime(d: string) { return d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
const statusBadge: Record<string, any> = { draft: 'warning', final: 'info', posted: 'success' };
// Label & warna aksi log status jurnal (append-only).
const logActionLabel: Record<string, string> = { created: 'Dibuat', finalized: 'Difinalisasi', approved: 'Di-approve', unapproved: 'Batal approve', rejected: 'Dikembalikan ke Baru' };
const logActionColor: Record<string, string> = { created: 'text-neutral-500', finalized: 'text-sky-600 dark:text-sky-400', approved: 'text-emerald-600 dark:text-emerald-400', unapproved: 'text-amber-600 dark:text-amber-400', rejected: 'text-danger-600 dark:text-danger-400' };

// Rincian baris (akun debit/kredit) tiap jurnal. Default mengikuti mode halaman:
//  Master → "Jurnal" = tersembunyi (ringkas); Query → "Detail Transaksi Jurnal" = tampil.
const showDetail = ref(props.defaultDetail ?? false);
const open = ref<Record<number, boolean>>({});
function toggle(id: number) { open.value[id] = !open.value[id]; }
function isOpen(id: number) { return showDetail.value || open.value[id]; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> {{ title ?? 'Jurnal' }}</h1>
            <Link :href="route('bs.gl.journal.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Jurnal Memorial</Button></Link>
        </div>

        <!-- Filter: periode tanggal + sumber + status + cari -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label><input v-model="dateFrom" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label><input v-model="dateTo" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber</label><select v-model="source" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua Jenis</option><option v-for="(label, val) in sources" :key="val" :value="val">{{ label }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Status</label><select v-model="statusFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">User Input</label><select v-model="createdBy" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua User</option><option v-for="u in (creators ?? [])" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
            <div class="flex-1 min-w-[160px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="No. Jurnal / Keterangan / Ref" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            <button @click="exportExcel" class="h-9 rounded-lg bg-success-600 px-5 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-neutral-500">Periode <span class="font-medium">{{ fmtDate(filters.date_from) }}</span> s/d <span class="font-medium">{{ fmtDate(filters.date_to) }}</span> · {{ journals.total }} jurnal</p>
            <button @click="showDetail = !showDetail" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">
                <ListTree class="h-3.5 w-3.5" /> {{ showDetail ? 'Sembunyikan Rincian' : 'Tampilkan Rincian' }}
            </button>
        </div>

        <!-- Bulk approval jurnal Final -->
        <div v-if="canApprove && selected.length" class="flex flex-wrap items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 dark:border-primary-500/30 dark:bg-primary-500/10">
            <span class="text-sm font-semibold text-primary-800 dark:text-primary-300">{{ selected.length }} jurnal terpilih</span>
            <button @click="bulkApprove" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"><Check class="h-3.5 w-3.5" /> Approve Terpilih</button>
            <button @click="selected = []" class="text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Batal pilih</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-2 py-3 w-8 text-center"><input v-if="canApprove" type="checkbox" v-model="allChecked" class="h-4 w-4" title="Pilih semua jurnal Final" /></th><th class="px-2 py-3 w-8"></th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-center">Baris</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="j in journals.data" :key="j.id">
                        <tr class="hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40" :class="selected.includes(Number(j.id)) ? 'bg-primary-50/40 dark:bg-primary-500/5' : ''">
                            <td class="px-2 py-2.5 text-center"><input v-if="canApprove && j.status === 'final'" type="checkbox" :value="Number(j.id)" v-model="selected" class="h-4 w-4" /></td>
                            <td class="px-2 py-2.5 text-center"><button type="button" @click="toggle(j.id)" class="text-neutral-400 hover:text-primary-600"><ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': isOpen(j.id) }" /></button></td>
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ j.journal_number }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(j.journal_date) }}</td>
                            <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                            <td class="px-4 py-2.5">{{ j.description }}<span v-if="j.reference" class="block text-xs text-neutral-400">Ref: {{ j.reference }}</span></td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[j.status] ?? 'neutral'" size="sm">{{ statuses[j.status] ?? j.status }}</Badge></td>
                            <td class="px-4 py-2.5 text-center">{{ j.lines_count }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <button v-if="canApprove && j.status === 'final'" @click="approveOne(j)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700"><Check class="h-3.5 w-3.5" /> Approve</button>
                                    <Link :href="route('bs.gl.journal.show', j.id)" class="text-primary-600 text-sm">Lihat →</Link>
                                </div>
                            </td>
                        </tr>
                        <!-- Rincian baris jurnal (akun debit/kredit) -->
                        <tr v-if="isOpen(j.id)" class="bg-neutral-50 dark:bg-neutral-900/40">
                            <td></td>
                            <td></td>
                            <td colspan="8" class="px-4 py-2">
                                <table class="w-full text-xs">
                                    <thead><tr class="text-left text-neutral-400"><th class="py-1 pl-2">Akun</th><th class="py-1">Keterangan Baris</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right pr-2">Kredit</th></tr></thead>
                                    <tbody>
                                        <tr v-for="ln in (j.lines ?? [])" :key="ln.id" class="border-t border-neutral-200/70 dark:border-neutral-800">
                                            <td class="py-1 pl-2 font-mono">{{ ln.account?.code }} — {{ ln.account?.name }}</td>
                                            <td class="py-1 text-neutral-500">{{ ln.description ?? '—' }}</td>
                                            <td class="py-1 text-right font-mono">{{ Number(ln.debit) > 0 ? fmtRp(ln.debit) : '' }}</td>
                                            <td class="py-1 text-right font-mono pr-2">{{ Number(ln.credit) > 0 ? fmtRp(ln.credit) : '' }}</td>
                                        </tr>
                                        <tr class="border-t border-neutral-300 font-semibold dark:border-neutral-700">
                                            <td class="py-1 pl-2" colspan="2">Total</td>
                                            <td class="py-1 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                                            <td class="py-1 text-right font-mono pr-2">{{ fmtRp(j.total_credit) }}</td>
                                        </tr>
                                    </tbody>
                                </table>

                                <!-- Riwayat Status (append-only): dibuat → finalisasi → approve → batal approve → reject. Tidak pernah hilang. -->
                                <div class="mt-3 border-t border-neutral-200 pt-2 dark:border-neutral-800">
                                    <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-neutral-500">
                                        <span>Riwayat Status</span>
                                        <span class="text-neutral-400">· Input oleh {{ j.created_by?.name ?? '—' }}</span>
                                    </p>
                                    <ol v-if="(j.status_logs ?? []).length" class="space-y-1">
                                        <li v-for="log in j.status_logs" :key="log.id" class="flex flex-wrap items-center gap-x-2 text-xs">
                                            <span class="font-mono text-neutral-400">{{ fmtDateTime(log.created_at) }}</span>
                                            <span class="font-semibold" :class="logActionColor[log.action] ?? 'text-neutral-600'">{{ logActionLabel[log.action] ?? log.action }}</span>
                                            <span v-if="log.from_status" class="text-neutral-400">({{ statuses[log.from_status] ?? log.from_status }} → {{ statuses[log.to_status] ?? log.to_status }})</span>
                                            <span class="text-neutral-500">oleh {{ log.user?.name ?? '—' }}</span>
                                        </li>
                                    </ol>
                                    <p v-else class="text-xs text-neutral-400">Belum ada riwayat status untuk jurnal ini.</p>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!journals.data?.length" title="Belum ada jurnal pada periode ini" description="Ubah rentang tanggal atau buat Jurnal Memorial." />
        </div>
    </div>
</template>
