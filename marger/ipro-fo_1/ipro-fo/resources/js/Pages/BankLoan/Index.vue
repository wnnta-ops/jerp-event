<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Plus, Download, Layers, Search, FilterX } from 'lucide-vue-next';
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

        <!-- Ringkasan per Status General (mengikuti filter; klik utk saring status) -->
        <div class="flex flex-wrap gap-2">
            <button v-for="s in statusSummary" :key="s.value" type="button" @click="toggleStatus(s.label)"
                class="card flex items-center gap-2 px-3 py-2 text-sm transition hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                :class="col.status.includes(s.label) ? 'ring-2 ring-primary-400' : ''">
                <span class="h-2.5 w-2.5 rounded-full" :class="dotClass(s.color)"></span>
                <span class="text-xs text-neutral-500">{{ s.label }}</span>
                <span class="text-base font-bold">{{ s.count }}</span>
            </button>
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
