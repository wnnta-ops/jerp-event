<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, RotateCcw, CheckCircle2, ArrowRight } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ScrollTopButton from '@/Components/UI/ScrollTopButton.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';
import Pagination from '@/Components/UI/Pagination.vue';

type Row = {
    id: number; keterangan: string | null; customer: string | null; unit: string | null; booking: string | null;
    doc_date: string | null; total: number; dpp_preview: number; ppn_preview: number;
    converted_at: string | null; journal_number: string | null;
    akad_status: 'sudah' | 'belum' | 'batal' | null; akad_at: string | null;
};
const props = defineProps<{
    /** Hasil saringan server, 50 baris per halaman. */
    rows: { data: Row[]; links?: any[]; from?: number | null; to?: number | null; total?: number };
    totalTersaring: number; jumlahSemua: number; filters: { q?: string; akad?: string };
    tab: string; counts: { pending: number; selesai: number };
}>();

const isSelesai = computed(() => props.tab === 'selesai');
function switchTab(t: string) { router.get(route('bs.cashbook.approval-um'), { tab: t }, { preserveState: false }); }

// ── Filter — DIKERJAKAN SERVER (50 baris per halaman) ──
const fq = ref(props.filters?.q ?? '');
const fAkad = ref(props.filters?.akad ?? '');
function resetFilter() { fq.value = ''; fAkad.value = ''; }
let jedaSaring: ReturnType<typeof setTimeout> | null = null;
watch([fq, fAkad], () => {
    if (jedaSaring) clearTimeout(jedaSaring);
    jedaSaring = setTimeout(() => router.get(route('bs.cashbook.approval-um'), { tab: props.tab, q: fq.value || undefined, akad: fAkad.value || undefined },
        { preserveScroll: true, preserveState: true, replace: true }), 400);
});
const filtered = computed<Row[]>(() => props.rows.data ?? []);
const totalPending = computed(() => props.totalTersaring ?? 0);

// ── Centang & Setujui massal (tab Menunggu) — tanpa isian, server memproses satu per satu. ──
const dipilih = ref<number[]>([]);
const barisDipilih = computed(() => filtered.value.filter((r) => dipilih.value.includes(r.id)));
const totalDipilih = computed(() => barisDipilih.value.reduce((a, r) => a + Number(r.total || 0), 0));
const semuaDicentang = computed({
    get: () => filtered.value.length > 0 && filtered.value.every((r) => dipilih.value.includes(r.id)),
    set: (v: boolean) => { dipilih.value = v ? filtered.value.map((r) => r.id) : []; },
});
const prosesMassal = ref(false);
function setujuiMassal() {
    const rows = barisDipilih.value;
    if (!rows.length || prosesMassal.value) return;
    if (!confirm(`Setujui & Posting ${rows.length} UM Penjualan (total ${fmtRp(totalDipilih.value)})?\n\nJurnal per entri: Utang Usaha (D) — Uang Muka Penjualan + PPN Keluaran (K).`)) return;
    prosesMassal.value = true;
    router.post(route('bs.cashbook.approval-um.bulk-post'), { ids: rows.map((r) => r.id), labels: rows.map((r) => `${r.customer ?? '—'}${r.unit ? ' · ' + r.unit : ''}`) },
        { preserveScroll: true, onSuccess: () => { dipilih.value = []; }, onFinish: () => { prosesMassal.value = false; } });
}

const posting = ref<number | null>(null);
function approve(r: Row) {
    if (posting.value) return;
    if (! confirm(`Setujui & Posting UM Penjualan?\n\n${r.customer ?? '—'}${r.unit ? ' · Unit ' + r.unit : ''}\nNominal ${fmtRp(r.total)}\n\nJurnal: Utang Usaha (D) — Uang Muka Penjualan ${fmtRp(r.dpp_preview)} (K) + PPN Keluaran ${fmtRp(r.ppn_preview)} (K).`)) return;
    posting.value = r.id;
    router.post(route('bs.cashbook.approval-um.post', r.id), {}, {
        preserveScroll: true,
        onFinish: () => (posting.value = null),
    });
}

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function d(v: any) { return v ?? '—'; }

const akadMeta: Record<string, { label: string; variant: 'success' | 'warning' | 'danger' }> = {
    sudah: { label: 'Sudah Akad', variant: 'success' },
    belum: { label: 'Belum Akad', variant: 'warning' },
    batal: { label: 'Batal', variant: 'danger' },
};
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-primary-600" /> Approval UM Penjualan</h1>
            <p class="text-sm text-neutral-500">
                Lanjutan dari <strong>Approval UTJ &amp; DP</strong>. Setujui &amp; Posting di sini mengonversi dana yang masih tercatat di
                <em>Utang Usaha</em> menjadi <strong>Uang Muka Penjualan</strong> — jurnal GL otomatis terposting.
            </p>
        </div>

        <!-- Alur jurnal: bantu operator paham posisi tahap ini -->
        <div class="card flex flex-wrap items-center gap-2 p-3 text-xs">
            <span class="font-semibold uppercase tracking-wide text-neutral-400">Alur jurnal</span>
            <span class="rounded-md bg-neutral-100 px-2 py-1 font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">UTJ &amp; DP: Bank (D) → Utang Usaha (K)</span>
            <ArrowRight class="h-3.5 w-3.5 text-neutral-400" />
            <span class="rounded-md bg-primary-50 px-2 py-1 font-semibold text-primary-700 ring-1 ring-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:ring-primary-800">Tahap ini: Utang Usaha (D) → Uang Muka Penjualan + PPN Keluaran (K)</span>
        </div>

        <!-- Tab -->
        <div class="flex w-fit gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
            <button @click="switchTab('pending')" class="rounded px-3 py-1.5 font-medium transition" :class="tab === 'pending' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Menunggu Approval <span class="opacity-70">({{ counts.pending }})</span></button>
            <button @click="switchTab('selesai')" class="rounded px-3 py-1.5 font-medium transition" :class="tab === 'selesai' ? 'bg-success-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Sudah Diposting <span class="opacity-70">({{ counts.selesai }})</span></button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">
                {{ rows.total ?? 0 }} dari {{ jumlahSemua }} entri
                <span v-if="! isSelesai" class="ml-2">· total <span class="font-mono font-semibold text-neutral-700 dark:text-neutral-200">{{ fmtRp(totalPending) }}</span></span>
            </p>
            <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
        </div>

        <div v-if="! isSelesai && filtered.length" class="card flex flex-wrap items-center gap-3 p-3 text-sm">
            <label class="flex items-center gap-2 font-medium"><input v-model="semuaDicentang" type="checkbox" class="h-4 w-4 rounded border-neutral-300" /> Pilih semua di halaman ini</label>
            <span class="text-neutral-500">{{ barisDipilih.length }} terpilih<span v-if="barisDipilih.length"> · {{ fmtRp(totalDipilih) }}</span></span>
            <Button v-if="barisDipilih.length" class="ml-auto" size="sm" variant="success" :loading="prosesMassal" @click="setujuiMassal">Setujui &amp; Posting ({{ barisDipilih.length }})</Button>
        </div>

        <TableTopScroll v-if="filtered.length || fq || fAkad">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-left text-xs uppercase text-neutral-500 dark:border-neutral-700">
                        <th class="px-3 py-2">Keterangan</th>
                        <th class="px-3 py-2">Customer / Unit</th>
                        <th class="px-3 py-2">Status Akad</th>
                        <th class="px-3 py-2 text-right">Nominal</th>
                        <th class="px-3 py-2 text-right">Uang Muka (DPP)</th>
                        <th class="px-3 py-2 text-right">PPN Keluaran</th>
                        <th class="px-3 py-2">{{ isSelesai ? 'Jurnal' : 'Aksi' }}</th>
                    </tr>
                    <!-- Baris filter -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th colspan="2" class="px-3 py-1.5">
                            <input v-model="fq" type="text" placeholder="Cari keterangan / customer / unit…"
                                class="h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-800" />
                        </th>
                        <th class="px-3 py-1.5">
                            <select v-model="fAkad" class="h-7 w-full rounded border border-neutral-300 bg-white px-1 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-800">
                                <option value="">Semua</option>
                                <option value="sudah">Sudah Akad</option>
                                <option value="belum">Belum Akad</option>
                                <option value="batal">Batal</option>
                                <option value="-">Tanpa unit</option>
                            </select>
                        </th>
                        <th colspan="4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                        <td class="px-3 py-2">
                            <label v-if="! isSelesai" class="mb-1 inline-flex items-center gap-1.5 text-[11px] text-neutral-500"><input v-model="dipilih" type="checkbox" :value="r.id" class="h-4 w-4 rounded border-neutral-300" /> pilih</label>
                            <p class="font-medium">{{ d(r.keterangan) }}</p>
                            <p class="text-xs text-neutral-400">{{ d(r.doc_date) }}<span v-if="r.booking"> · {{ r.booking }}</span></p>
                        </td>
                        <td class="px-3 py-2">
                            <p class="whitespace-nowrap">{{ d(r.customer) }}</p>
                            <p v-if="r.unit" class="font-mono text-xs text-neutral-400">{{ r.unit }}</p>
                        </td>
                        <td class="px-3 py-2">
                            <Badge v-if="r.akad_status" :variant="akadMeta[r.akad_status].variant" size="sm">{{ akadMeta[r.akad_status].label }}</Badge>
                            <span v-else class="text-xs text-neutral-400" title="Entri tanpa unit/booking — tidak dapat ditelusuri ke Proses KPR">—</span>
                            <p v-if="r.akad_at" class="mt-0.5 text-[11px] text-neutral-400">{{ r.akad_at }}</p>
                        </td>
                        <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                        <td class="px-3 py-2 text-right font-mono text-success-700 dark:text-success-400">{{ fmtRp(r.dpp_preview) }}</td>
                        <td class="px-3 py-2 text-right font-mono text-info-600 dark:text-info-400">{{ fmtRp(r.ppn_preview) }}</td>
                        <td class="px-3 py-2">
                            <div v-if="isSelesai" class="text-xs">
                                <Badge variant="success" size="sm">Terposting</Badge>
                                <p class="mt-0.5 font-mono text-neutral-500">{{ d(r.journal_number) }}</p>
                                <p class="text-[11px] text-neutral-400">{{ d(r.converted_at) }}</p>
                            </div>
                            <Button v-else variant="success" size="sm" :loading="posting === r.id" :disabled="posting !== null && posting !== r.id" @click="approve(r)">
                                <template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Setujui &amp; Posting
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="! filtered.length">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-500">Tidak ada entri yang cocok dengan filter.</td>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <EmptyState v-else
            :title="isSelesai ? 'Belum ada yang diposting' : 'Tidak ada yang menunggu approval'"
            :description="isSelesai ? 'Entri yang sudah dikonversi ke Uang Muka Penjualan akan tampil di sini.' : 'Entri muncul di sini setelah TTS disetujui & diposting pada Approval UTJ & DP.'">
            <template #icon><Wallet class="h-12 w-12" /></template>
        </EmptyState>
        <Pagination :data="rows" satuan="entri" />

        <ScrollTopButton />
    </div>
</template>
