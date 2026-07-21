<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Plus, Download, Layers, Search, FilterX, ChevronDown, ClipboardList, Landmark, Flag } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

const props = defineProps<{ applications: any[]; statuses: Array<any> }>();

const allRows = computed<any[]>(() => props.applications ?? []);

function fmtNum(v: number | null) { return (v === null || v === undefined) ? '—' : new Intl.NumberFormat('id-ID').format(v); }
function dash(v: any) { return (v === null || v === undefined || v === '') ? '—' : v; }
function statusColor(label: string) { return props.statuses.find(s => s.label === label)?.color ?? 'neutral'; }
function keputusanVariant(k: string | null) { return k === 'Disetujui' ? 'success' : (k === 'Batal' ? 'danger' : 'neutral'); }
function dotClass(color: string) { return ({ success: 'bg-emerald-500', danger: 'bg-danger-500', warning: 'bg-amber-500', info: 'bg-sky-500', neutral: 'bg-neutral-400', primary: 'bg-primary-500' } as Record<string, string>)[color] ?? 'bg-neutral-400'; }

// Nilai tampilan per-kolom (utama) — dipakai utk opsi filter & pencocokan agar konsisten dgn render.
const dNo = (a: any) => a.no_kpr ?? '';
const dStatus = (a: any) => a.status ?? '—';
const dCust = (a: any) => a.customer ?? '—';
const dProject = (a: any) => a.project ?? '—';
const dCluster = (a: any) => a.cluster ?? '—';
const dUnit = (a: any) => a.unit ?? '—';
const dBank = (a: any) => a.bank ?? '—';

function distinct(fn: (a: any) => string) {
    const s = new Set<string>();
    for (const a of allRows.value) s.add(fn(a));
    return [...s].sort((x, y) => x.localeCompare(y, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optCust = computed(() => distinct(dCust));
const optProject = computed(() => distinct(dProject));
const optCluster = computed(() => distinct(dCluster));
const optUnit = computed(() => distinct(dUnit));
const optBank = computed(() => distinct(dBank));
const optStatus = computed(() => distinct(dStatus));

// Filter global (ketik) + checklist per-kolom (kosong = semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], status: [], cust: [], project: [], cluster: [], unit: [], bank: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

function matchesGlobal(a: any) {
    if (!search.value) return true;
    const q = search.value.toLowerCase();
    return [dNo(a), dStatus(a), dCust(a), dProject(a), dCluster(a), dUnit(a), dBank(a)].join(' ').toLowerCase().includes(q);
}
// Filter semua kolom KECUALI status → dasar penghitungan ringkasan status (facet).
function matchesNonStatus(a: any) {
    if (!matchesGlobal(a)) return false;
    if (col.no.length && !col.no.includes(dNo(a))) return false;
    if (col.cust.length && !col.cust.includes(dCust(a))) return false;
    if (col.project.length && !col.project.includes(dProject(a))) return false;
    if (col.cluster.length && !col.cluster.includes(dCluster(a))) return false;
    if (col.unit.length && !col.unit.includes(dUnit(a))) return false;
    if (col.bank.length && !col.bank.includes(dBank(a))) return false;
    return true;
}
const filteredBase = computed(() => allRows.value.filter(matchesNonStatus));
const filtered = computed(() => filteredBase.value.filter((a) => !col.status.length || col.status.includes(dStatus(a))));

// Ringkasan per status general (mengikuti filter kolom lain; klik utk filter status).
const statusSummary = computed(() => props.statuses.map((s: any) => ({ ...s, count: filteredBase.value.filter((a) => dStatus(a) === s.label).length })));
function toggleStatus(label: string) { const i = col.status.indexOf(label); if (i >= 0) col.status.splice(i, 1); else col.status.push(label); }

// Ringkasan status dikelompokkan ke 3 tahap alur KPR (Pra → Di Bank → Pasca), masing-masing bisa
// dibuka-tutup. Urutan status di tiap seksi mengikuti urutan enum (= urutan alur). Nilai `values`
// memakai `value` status (bukan label) agar tahan perubahan teks label.
const SECTION_DEFS = [
    { key: 'pra',   title: 'Pra Proses KPR',     sub: 'Pengajuan awal',       icon: ClipboardList, iconCls: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-300', values: ['draft', 'submitted'] },
    { key: 'bank',  title: 'Proses KPR di Bank', sub: 'Verifikasi & analisa', icon: Landmark,      iconCls: 'bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-300',           values: ['wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'in_review', 'sp3k', 'si', 'akad'] },
    { key: 'pasca', title: 'Pasca Proses KPR',   sub: 'Hasil akhir',          icon: Flag,          iconCls: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300', values: ['disbursed', 'active', 'rejected', 'cancelled'] },
];
const openSec = reactive<Record<string, boolean>>({ pra: true, bank: true, pasca: true });
function toggleSec(k: string) { openSec[k] = !openSec[k]; }
const sections = computed(() => SECTION_DEFS.map((sec) => {
    const items = statusSummary.value.filter((s: any) => sec.values.includes(s.value));
    return {
        ...sec,
        items,
        total: items.reduce((n: number, s: any) => n + s.count, 0),
        active: items.some((s: any) => col.status.includes(s.label)),
    };
}));

watch([optNo, optCust, optProject, optCluster, optUnit, optBank, optStatus], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.cust = prune(col.cust, optCust.value); col.project = prune(col.project, optProject.value);
    col.cluster = prune(col.cluster, optCluster.value); col.unit = prune(col.unit, optUnit.value); col.bank = prune(col.bank, optBank.value); col.status = prune(col.status, optStatus.value);
});

// Scrollbar horizontal kembar DI ATAS tabel, disinkronkan dua arah dengan scroller tabel.
const topScroll = ref<HTMLElement | null>(null);
const tableScroll = ref<HTMLElement | null>(null);
const tableEl = ref<HTMLElement | null>(null);
const tableWidth = ref(0);
let syncing = false;
function onTopScroll() { if (syncing || !tableScroll.value || !topScroll.value) return; syncing = true; tableScroll.value.scrollLeft = topScroll.value.scrollLeft; syncing = false; }
function onTableScroll() { if (syncing || !tableScroll.value || !topScroll.value) return; syncing = true; topScroll.value.scrollLeft = tableScroll.value.scrollLeft; syncing = false; }
function updateWidth() { tableWidth.value = tableEl.value?.scrollWidth ?? tableScroll.value?.scrollWidth ?? 0; }
let ro: ResizeObserver | null = null;
onMounted(() => {
    nextTick(updateWidth);
    window.addEventListener('resize', updateWidth);
    if (typeof ResizeObserver !== 'undefined' && tableEl.value) { ro = new ResizeObserver(() => updateWidth()); ro.observe(tableEl.value); }
});
onBeforeUnmount(() => { window.removeEventListener('resize', updateWidth); ro?.disconnect(); });
watch(filtered, () => nextTick(updateWidth));
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Proses KPR</h1>
            <div class="flex items-center gap-2">
                <a :href="route('exports.kpr', { search: search || undefined })" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Download class="h-4 w-4" /> Export Excel</a>
                <Link :href="route('kpr.disbursement-bulk')"><Button variant="secondary"><template #icon-left><Layers class="h-4 w-4" /></template>Pencairan Retensi (Massal)</Button></Link>
                <Link :href="route('kpr.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Proses KPR Baru</Button></Link>
            </div>
        </div>

        <!-- Ringkasan status — 3 tahap alur KPR (Pra → Di Bank → Pasca), tiap seksi buka-tutup dgn
             animasi. Bar distribusi tetap tampak walau ditutup. Klik chip status utk menyaring tabel
             (facet, mengikuti filter kolom lain). -->
        <div class="space-y-2">
            <section v-for="sec in sections" :key="sec.key" class="card overflow-hidden transition-shadow hover:shadow-card-hover"
                :class="sec.active ? 'ring-1 ring-primary-300 dark:ring-primary-500/40' : ''">
                <button type="button" @click="toggleSec(sec.key)" class="flex w-full items-center gap-3 px-4 py-3 text-left">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="sec.iconCls">
                        <component :is="sec.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <span class="text-sm font-semibold">{{ sec.title }}</span>
                            <span v-if="sec.active" class="rounded-full bg-primary-100 px-1.5 py-0.5 text-[10px] font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">difilter</span>
                        </span>
                        <span class="block text-xs text-neutral-400">{{ sec.sub }}</span>
                    </span>
                    <span class="ml-auto flex items-center gap-3">
                        <span class="text-right leading-none">
                            <span class="block text-xl font-bold tabular-nums">{{ sec.total }}</span>
                            <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-neutral-400">proses</span>
                        </span>
                        <ChevronDown class="h-5 w-5 shrink-0 text-neutral-400 transition-transform duration-300" :class="openSec[sec.key] ? 'rotate-180' : ''" />
                    </span>
                </button>
                <!-- Bar distribusi proporsi (selalu tampak, termasuk saat seksi ditutup) -->
                <div class="flex h-1.5 gap-px overflow-hidden px-4 pb-3">
                    <template v-for="s in sec.items" :key="s.value">
                        <span v-if="s.count" class="h-full rounded-full" :class="dotClass(s.color)" :style="{ width: (s.count / Math.max(sec.total, 1) * 100) + '%' }" :title="`${s.label}: ${s.count}`"></span>
                    </template>
                    <span v-if="!sec.total" class="h-full w-full rounded-full bg-neutral-100 dark:bg-neutral-800"></span>
                </div>
                <!-- Chips (buka-tutup: animasi tinggi 0fr↔1fr + fade) -->
                <div class="grid transition-all duration-300 ease-out" :class="openSec[sec.key] ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
                    <div class="overflow-hidden">
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2 border-t border-neutral-100 p-3 dark:border-neutral-800">
                            <button v-for="s in sec.items" :key="s.value" type="button" @click="toggleStatus(s.label)"
                                class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition-all duration-150 hover:-translate-y-0.5 hover:shadow-card"
                                :class="col.status.includes(s.label) ? 'border-primary-400 bg-primary-50/60 ring-1 ring-primary-400 dark:bg-primary-900/10' : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700 dark:hover:border-neutral-600'">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="dotClass(s.color)"></span>
                                <span class="min-w-0 flex-1 text-xs leading-tight text-neutral-500">{{ s.label }}</span>
                                <span class="text-lg font-bold tabular-nums" :class="s.count ? '' : 'text-neutral-300 dark:text-neutral-600'">{{ s.count }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" placeholder="Cari semua kolom (ketik)…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
            <span class="text-xs text-neutral-400">{{ filtered.length }}<span v-if="hasFilter"> / {{ allRows.length }}</span> proses</span>
        </div>

        <!-- Layout lifecycle bertingkat (Draft → Submitted → SP3K → Keputusan → Akad) -->
        <div class="card overflow-hidden">
            <div ref="topScroll" @scroll="onTopScroll" class="overflow-x-auto overflow-y-hidden border-b border-neutral-100 dark:border-neutral-800">
                <div class="h-3" :style="{ width: tableWidth + 'px' }"></div>
            </div>
            <div ref="tableScroll" @scroll="onTableScroll" class="overflow-auto max-h-[70vh]">
                <table ref="tableEl" class="w-full whitespace-nowrap text-xs">
                    <thead class="sticky top-0 z-20 bg-neutral-100 dark:bg-neutral-800">
                        <tr class="text-center text-[11px] font-semibold uppercase text-neutral-500">
                            <th class="px-3 py-2 text-left align-top" rowspan="2">No. KPR<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.no" :options="optNo" /></div></th>
                            <th class="px-3 py-2 align-top border-l border-neutral-200 dark:border-neutral-700" rowspan="2">Status General<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.status" :options="optStatus" /></div></th>
                            <th class="px-3 py-2 text-left align-top border-l border-neutral-200 dark:border-neutral-700" rowspan="2">Customer<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.cust" :options="optCust" /></div></th>
                            <th class="px-3 py-2 text-left align-top" rowspan="2">Project<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.project" :options="optProject" /></div></th>
                            <th class="px-3 py-2 text-left align-top" rowspan="2">Cluster<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.cluster" :options="optCluster" /></div></th>
                            <th class="px-3 py-2 text-left align-top" rowspan="2">Unit<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.unit" :options="optUnit" /></div></th>
                            <th class="px-3 py-2 text-left align-top" rowspan="2">Bank<div class="mt-1 normal-case font-normal"><ColumnFilter v-model="col.bank" :options="optBank" /></div></th>
                            <th class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-700" colspan="2">Draft</th>
                            <th class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-700" colspan="4">Submitted</th>
                            <th class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-700" colspan="5">SP3K</th>
                            <th class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-700" colspan="2">Keputusan</th>
                            <th class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-700" colspan="5">Akad</th>
                        </tr>
                        <tr class="text-center text-[10px] uppercase text-neutral-400">
                            <th class="px-3 py-1.5 text-right border-l border-neutral-200 dark:border-neutral-700">Nominal</th>
                            <th class="px-3 py-1.5">Tanggal</th>
                            <th class="px-3 py-1.5 border-l border-neutral-200 dark:border-neutral-700">Nama Bank</th>
                            <th class="px-3 py-1.5 text-right">Nominal</th>
                            <th class="px-3 py-1.5">Tanggal</th>
                            <th class="px-3 py-1.5 text-right">Aging</th>
                            <th class="px-3 py-1.5 border-l border-neutral-200 dark:border-neutral-700">Nama Bank</th>
                            <th class="px-3 py-1.5">No. SP3K</th>
                            <th class="px-3 py-1.5 text-right">Nominal</th>
                            <th class="px-3 py-1.5">Tanggal</th>
                            <th class="px-3 py-1.5 text-right">Aging</th>
                            <th class="px-3 py-1.5 border-l border-neutral-200 dark:border-neutral-700">Keputusan</th>
                            <th class="px-3 py-1.5">Tanggal</th>
                            <th class="px-3 py-1.5 border-l border-neutral-200 dark:border-neutral-700">Nama Bank</th>
                            <th class="px-3 py-1.5">No. SP3K</th>
                            <th class="px-3 py-1.5 text-right">Nominal</th>
                            <th class="px-3 py-1.5">Tanggal</th>
                            <th class="px-3 py-1.5 text-right">Aging</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="a in filtered" :key="a.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-3 py-2"><Link :href="route('kpr.show', a.id)" class="font-mono text-primary-600 hover:underline">{{ a.no_kpr }}</Link></td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800"><Badge :variant="statusColor(a.status)" dot>{{ a.status }}</Badge></td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800">{{ dash(a.customer) }}</td>
                            <td class="px-3 py-2">{{ dash(a.project) }}</td>
                            <td class="px-3 py-2">{{ dash(a.cluster) }}</td>
                            <td class="px-3 py-2 font-mono">{{ dash(a.unit) }}</td>
                            <td class="px-3 py-2">{{ dash(a.bank) }}</td>
                            <td class="px-3 py-2 text-right font-mono border-l border-neutral-200 dark:border-neutral-800">{{ fmtNum(a.draft.nominal) }}</td>
                            <td class="px-3 py-2">{{ dash(a.draft.tanggal) }}</td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800">{{ dash(a.submitted.bank) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtNum(a.submitted.nominal) }}</td>
                            <td class="px-3 py-2">{{ dash(a.submitted.tanggal) }}</td>
                            <td class="px-3 py-2 text-right">{{ dash(a.submitted.aging) }}</td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800">{{ dash(a.sp3k.bank) }}</td>
                            <td class="px-3 py-2 font-mono">{{ dash(a.sp3k.no) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtNum(a.sp3k.nominal) }}</td>
                            <td class="px-3 py-2">{{ dash(a.sp3k.tanggal) }}</td>
                            <td class="px-3 py-2 text-right">{{ dash(a.sp3k.aging) }}</td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800">
                                <Badge v-if="a.keputusan.keputusan" size="sm" :variant="keputusanVariant(a.keputusan.keputusan)">{{ a.keputusan.keputusan }}</Badge>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-3 py-2">{{ dash(a.keputusan.tanggal) }}</td>
                            <td class="px-3 py-2 border-l border-neutral-200 dark:border-neutral-800">{{ dash(a.akad.bank) }}</td>
                            <td class="px-3 py-2 font-mono">{{ dash(a.akad.no) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtNum(a.akad.nominal) }}</td>
                            <td class="px-3 py-2">{{ dash(a.akad.tanggal) }}</td>
                            <td class="px-3 py-2 text-right">{{ dash(a.akad.aging) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!allRows.length" title="Belum ada Proses KPR" />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada Proses KPR yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
