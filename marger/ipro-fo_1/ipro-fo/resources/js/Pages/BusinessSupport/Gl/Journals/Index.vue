<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, FileText, ChevronDown, ListTree, Layers } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ journals: any; sources: Record<string, string>; statuses: Record<string, string>; filters: Record<string, any>; defaultDetail?: boolean; listRoute?: string; title?: string; canApprove?: boolean }>();

/* ── Aksi massal ────────────────────────────────────────────────────────────
   Empat aksi, masing-masing SATU LANGKAH pada rantai Baru → Final → Approved.
   Tidak ada yang melompati dua tahap: jurnal Approved harus dibatalkan approval-nya
   dulu (kembali Final) sebelum finalisasinya bisa dibatalkan.

   `dari` = status yang boleh ikut. Baris berstatus lain sengaja tidak bisa dicentang
   untuk aksi itu — lebih jujur daripada membiarkannya dicentang lalu ditolak server. */
type Aksi = { key: string; label: string; dari: string; jadi: string; rute: string; izin: 'gl' | 'approval'; nada: string };
const AKSI: Aksi[] = [
    { key: 'finalisasi',       label: 'Finalisasi Massal',        dari: 'draft',  jadi: 'final',  rute: 'bs.gl.journal.finalize-batch',        izin: 'gl',       nada: 'bg-primary-600 hover:bg-primary-700 text-white' },
    { key: 'approve',          label: 'Approve Massal',           dari: 'final',  jadi: 'posted', rute: 'bs.gl.journal.approve-batch',         izin: 'approval', nada: 'bg-success-600 hover:bg-success-700 text-white' },
    { key: 'batal-approve',    label: 'Cancel Approved Massal',   dari: 'posted', jadi: 'final',  rute: 'bs.gl.journal.cancel-batch',          izin: 'approval', nada: 'border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700' },
    { key: 'batal-finalisasi', label: 'Cancel Finalisasi Massal', dari: 'final',  jadi: 'draft',  rute: 'bs.gl.journal.cancel-finalize-batch', izin: 'gl',       nada: 'border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700' },
];

const aksiKey = ref<string>('finalisasi');
const aksi = computed<Aksi>(() => AKSI.find((a) => a.key === aksiKey.value) ?? AKSI[0]);
const aksiTersedia = computed(() => AKSI.filter((a) => a.izin === 'gl' || props.canApprove));

const dipilih = ref<number[]>([]);
/** Baris yang statusnya cocok dengan aksi terpilih — hanya ini yang bisa dicentang. */
const bisaDipilih = computed<number[]>(() =>
    (props.journals?.data ?? []).filter((j: any) => j.status === aksi.value.dari).map((j: any) => Number(j.id)));
function bolehPilih(j: any) { return j.status === aksi.value.dari; }

// Ganti aksi → centang lama tidak lagi relevan (statusnya beda).
watch(aksiKey, () => { dipilih.value = []; });
// Halaman/filter berubah → buang centang yang barisnya sudah tidak ada.
watch(() => props.journals, () => {
    const ada = new Set(bisaDipilih.value);
    dipilih.value = dipilih.value.filter((id) => ada.has(id));
});

const semuaTercentang = computed<boolean>({
    get: () => bisaDipilih.value.length > 0 && bisaDipilih.value.every((id) => dipilih.value.includes(id)),
    set: (v: boolean) => { dipilih.value = v ? [...bisaDipilih.value] : []; },
});

const memproses = ref(false);
function jalankanAksi() {
    if (! dipilih.value.length || memproses.value) return;
    const a = aksi.value;
    const pesan = `${a.label}: ${dipilih.value.length} jurnal akan berubah dari `
        + `${props.statuses[a.dari] ?? a.dari} menjadi ${props.statuses[a.jadi] ?? a.jadi}.\n\nLanjutkan?`;
    if (! confirm(pesan)) return;
    memproses.value = true;
    router.post(route(a.rute), { ids: dipilih.value }, {
        preserveScroll: true,
        onFinish: () => { memproses.value = false; dipilih.value = []; },
    });
}
const listRoute = props.listRoute ?? 'bs.gl.journal.index';
const source = ref(props.filters.source ?? '');
const statusFilter = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

function display() {
    router.get(route(listRoute), {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
}
function exportExcel() {
    window.location.href = route('bs.gl.journal.export', {
        source: source.value || undefined,
        status: statusFilter.value || undefined,
        search: search.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { draft: 'warning', final: 'info', posted: 'success' };

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

        <!-- ══ Aksi massal ══
             Pilih aksinya DULU, baru centang barisnya: tiap aksi hanya berlaku untuk satu
             status asal, jadi daftar yang bisa dicentang ikut berubah mengikuti pilihan.
             Urutan ini mencegah operator mencentang belasan baris lalu ditolak semuanya. -->
        <div class="card p-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2 text-sm font-semibold"><Layers class="h-4 w-4 text-primary-600" /> Aksi Massal</div>
                <select v-model="aksiKey" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option v-for="a in aksiTersedia" :key="a.key" :value="a.key">{{ a.label }}</option>
                </select>
                <span class="rounded-md bg-neutral-100 px-2 py-1 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    {{ statuses[aksi.dari] ?? aksi.dari }} → {{ statuses[aksi.jadi] ?? aksi.jadi }}
                </span>
                <span class="text-xs text-neutral-500">
                    {{ dipilih.length }} terpilih · {{ bisaDipilih.length }} bisa dipilih di halaman ini
                </span>
                <button type="button" @click="jalankanAksi" :disabled="!dipilih.length || memproses"
                        class="ml-auto inline-flex h-9 items-center gap-1.5 rounded-lg px-4 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
                        :class="aksi.nada">
                    {{ memproses ? 'Memproses…' : aksi.label }}<span v-if="dipilih.length"> ({{ dipilih.length }})</span>
                </button>
            </div>
            <p class="mt-2 text-[11.5px] leading-relaxed text-neutral-500">
                Setiap aksi bergerak <b>satu tahap</b> pada rantai Baru → Final → Approved.
                Jurnal <b>Approved tidak bisa langsung kembali ke Baru</b>: batalkan approval-nya dulu
                (Approved → Final), baru finalisasinya dibatalkan (Final → Baru).
                Baris yang statusnya tidak cocok dengan aksi terpilih tidak dapat dicentang.
            </p>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-2 py-3 w-8"><input v-model="semuaTercentang" type="checkbox" :disabled="!bisaDipilih.length" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :title="bisaDipilih.length ? 'Pilih semua yang berstatus ' + (statuses[aksi.dari] ?? aksi.dari) : 'Tidak ada jurnal berstatus ' + (statuses[aksi.dari] ?? aksi.dari) + ' di halaman ini'" /></th><th class="px-2 py-3 w-8"></th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-center">Baris</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="j in journals.data" :key="j.id">
                        <tr class="hover:bg-neutral-50/60 dark:hover:bg-neutral-800/40">
                            <td class="px-2 py-2.5 text-center"><input v-if="bolehPilih(j)" v-model="dipilih" type="checkbox" :value="Number(j.id)" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" /><span v-else class="cursor-not-allowed text-neutral-300 dark:text-neutral-600" :title="'Aksi ini hanya untuk jurnal berstatus ' + (statuses[aksi.dari] ?? aksi.dari)">—</span></td><td class="px-2 py-2.5 text-center"><button type="button" @click="toggle(j.id)" class="text-neutral-400 hover:text-primary-600"><ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': isOpen(j.id) }" /></button></td>
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ j.journal_number }}</td>
                            <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(j.journal_date) }}</td>
                            <td class="px-4 py-2.5"><Badge variant="info" size="sm">{{ sources[j.source] ?? j.source }}</Badge></td>
                            <td class="px-4 py-2.5">{{ j.description }}<span v-if="j.reference" class="block text-xs text-neutral-400">Ref: {{ j.reference }}</span></td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[j.status] ?? 'neutral'" size="sm">{{ statuses[j.status] ?? j.status }}</Badge></td>
                            <td class="px-4 py-2.5 text-center">{{ j.lines_count }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(j.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right"><Link :href="route('bs.gl.journal.show', j.id)" class="text-primary-600 text-sm">Lihat →</Link></td>
                        </tr>
                        <!-- Rincian baris jurnal (akun debit/kredit) -->
                        <tr v-if="isOpen(j.id)" class="bg-neutral-50 dark:bg-neutral-900/40">
                            <td></td>
                            <td colspan="9" class="px-4 py-2">
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
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-if="!journals.data?.length" title="Belum ada jurnal pada periode ini" description="Ubah rentang tanggal atau buat Jurnal Memorial." />
        </div>
    </div>
</template>
